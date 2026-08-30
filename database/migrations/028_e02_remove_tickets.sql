-- E0.2: Likwidacja tabeli `tickets` — Bilet jako widok Zapisu, nie byt.
--
-- Model domenowy (06 pkt 4): w łańcuchu startowym są cztery byty (Zapis, Wynik,
-- Rekord życiowy, Odprawa) i dwa nie-byty — Bilet (widok Zapisu) i Start (słowo
-- klienta na Zapis). Tabela `tickets` miała relację 1:1 z registrations i nigdy
-- nie niosła realnie żadnej własnej informacji poza `status='used'` — a to jest
-- w istocie pojęcie Odprawy (check-in), nie atrybut biletu.
--
-- registrations.checked_in_at zastępuje tickets.status: NULL = nie sprawdzono,
-- wypełnione = sprawdzono (i kiedy). To nie jest pełny model Odprawy (Biuro
-- Zawodów, osobny ticket) — tylko żeby nie zgubić informacji "czy i kiedy".
-- VARCHAR(64) storing DATE_ATOM, matching registered_at/removed_at's convention
-- in this table (app-level ISO-8601 strings, not native DATETIME columns).
ALTER TABLE registrations
  ADD COLUMN IF NOT EXISTS checked_in_at VARCHAR(64) NULL AFTER removed_at;

-- Best-effort backfill z tickets, zanim tabela zniknie poniżej. Migrator uruchamia
-- wszystkie pliki przy każdym starcie (brak osobnego ledgera migracji), więc po
-- pierwszym przebiegu `tickets` już nie istnieje — stąd sprawdzenie przez
-- information_schema + dynamiczne SQL, żeby kolejne przebiegi nie wywaliły się na
-- referencji do usuniętej tabeli. `issued_at` to jedyny dostępny timestamp — sama
-- tabela nigdy nie zapisywała momentu odprawy, więc to przybliżenie, nie prawda.
SET @tickets_exists = (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'tickets'
);
SET @backfill_sql = IF(
  @tickets_exists > 0,
  'UPDATE registrations r INNER JOIN tickets t ON t.registration_id = r.id
   SET r.checked_in_at = CONCAT(DATE_FORMAT(t.issued_at, ''%Y-%m-%dT%H:%i:%s''), ''+00:00'')
   WHERE t.status = ''used'' AND r.checked_in_at IS NULL',
  'DO 0'
);
PREPARE backfill_stmt FROM @backfill_sql;
EXECUTE backfill_stmt;
DEALLOCATE PREPARE backfill_stmt;

DROP TABLE IF EXISTS tickets;
