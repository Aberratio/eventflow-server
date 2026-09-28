-- E1.1: Cykl życia wydarzenia — trzy niezależne osie zamiast jednego przeciążonego
-- pola `status` (workflow moderacji + widoczność publikacji + "czy usunięte"
-- wszystko na raz).
--
-- Oś 1 `publication_status`: draft -> submitted -> (approved, przelotowo) ->
-- published -> finished, z rejected (przelotowo, wraca do draft) i cancelled
-- (terminalne, ręczne). `approved` i `rejected` istnieją w ENUM-ie na wyrost
-- (zgodnie ze spec) ale aplikacja nigdy w nich faktycznie nie spoczywa —
-- approve() ląduje odrazu w `published`, reject() w `draft` (z zachowanym
-- rejection_reason) — dokładnie tak jak już działały EventRepository::approve()/
-- reject() przed tym ticketem, tylko teraz nazwane zgodnie z osią.
--
-- Miękkie usuwanie wydzielone do własnej kolumny `deleted_at` — nie mieści się w
-- czystej osi workflow (dawne `status = 'deleted'` było nadużyciem tego pola).
--
-- Oś 2 `visibility` (public/private) formalizuje dotychczasowe `is_private` —
-- `is_private`/`access_code` zostają jako pole zapisu wewnętrznego, zsynchronizowane
-- z `visibility` w obie strony (access_code już istniał od wcześniejszego ticketu,
-- nie duplikujemy). Sensowne tylko gdy publication_status = published; nieegzek-
-- wowane na poziomie schematu, tylko w warstwie API (patrz routes.php).
--
-- Oś 3 `is_test`: niezależna flaga, ustawialna wyłącznie w Draft, kasowana
-- nieodwracalnie przy przejściu Draft -> Submitted.
--
-- "Pokaż informację o biegu wirtualnym" — sprawdzone: nieobecne w schemacie tego
-- repo (pole tylko-kliencie), nic do usunięcia po stronie backendu.

-- @guard: column-missing events.publication_status
ALTER TABLE events ADD COLUMN publication_status ENUM('draft','submitted','approved','published','finished','rejected','cancelled') NOT NULL DEFAULT 'draft';
-- @guard: column-missing events.visibility
ALTER TABLE events ADD COLUMN visibility ENUM('public','private') NOT NULL DEFAULT 'public';
-- @guard: column-missing events.is_test
ALTER TABLE events ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0;
-- @guard: column-missing events.deleted_at
ALTER TABLE events ADD COLUMN deleted_at VARCHAR(64) NULL;

-- Backfill z dawnego `status`, tylko dopóki ta kolumna jeszcze istnieje. Ten plik
-- musi zostać idempotentny niezależnie od ledgera migracji (patrz
-- 028_e02_remove_tickets.sql i Migrator.php), więc po pierwszym przebiegu `status`
-- już nie ma — stąd sprawdzenie przez information_schema + dynamiczne SQL, jak w 028.
SET @events_status_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'events' AND column_name = 'status'
);

SET @backfill_publication_status_sql = IF(
  @events_status_exists > 0,
  'UPDATE events SET publication_status = CASE status
     WHEN ''draft'' THEN ''draft''
     WHEN ''awaiting_approval'' THEN ''submitted''
     WHEN ''approved'' THEN ''published''
     WHEN ''active'' THEN ''published''
     WHEN ''past'' THEN ''finished''
     WHEN ''archived'' THEN ''finished''
     WHEN ''deleted'' THEN ''draft''
     ELSE ''draft''
   END
   WHERE publication_status = ''draft''',
  'DO 0'
);
PREPARE backfill_publication_status_stmt FROM @backfill_publication_status_sql;
EXECUTE backfill_publication_status_stmt;
DEALLOCATE PREPARE backfill_publication_status_stmt;

SET @backfill_deleted_at_sql = IF(
  @events_status_exists > 0,
  'UPDATE events SET deleted_at = COALESCE(updated_at, CURRENT_TIMESTAMP)
   WHERE status = ''deleted'' AND deleted_at IS NULL',
  'DO 0'
);
PREPARE backfill_deleted_at_stmt FROM @backfill_deleted_at_sql;
EXECUTE backfill_deleted_at_stmt;
DEALLOCATE PREPARE backfill_deleted_at_stmt;

UPDATE events SET visibility = 'private' WHERE is_private = 1 AND visibility <> 'private';

-- Stary indeks filtrujący po `status` musi zniknąć przed usunięciem kolumny.
-- @guard: index-exists events.idx_events_filters
ALTER TABLE events DROP INDEX idx_events_filters;

-- @guard: column-exists events.status
ALTER TABLE events DROP COLUMN status;

-- @guard: index-missing events.idx_events_filters
ALTER TABLE events ADD INDEX idx_events_filters (discipline, city, publication_status, registration_status);
