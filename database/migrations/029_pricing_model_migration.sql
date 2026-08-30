-- E0.3: Migracja schematu modelu cenowego
--
-- Rozstrzygnięcie: docs/07-model-cenowy-zapisu.md. Skraca sześć niezależnych
-- mechanizmów cenowych do czterech składników liczonych sekwencyjnie (Wariant,
-- Próg, Kod rabatowy, Rozliczenie); ten plik tylko przenosi schemat i dane w
-- docelowy kształt, nie liczy jeszcze pełnego algorytmu (ticket E1.2).
--
-- Migracje nie mają ledgera i uruchamiają się przy każdym starcie (patrz
-- Migrator.php), więc każdy krok musi być bezpieczny do powtórzenia: kolumny
-- znikają dopiero na końcu, a każdy backfill jest osłonięty sprawdzeniem przez
-- information_schema (ten sam wzorzec co 028_e02_remove_tickets.sql), żeby
-- drugi i kolejne przebiegi nie odwoływały się do już usuniętej kolumny.

-- ── 1. Próg Wariantu: dwa niezależne opcjonalne limity, bez trybu XOR ───────
-- `price_tier_mode` wymuszał wybór data-albo-liczba-uczestników; próg teraz
-- może nieść oba limity naraz i wygasa na pierwszym przekroczonym. Struktura
-- JSON w `price_tiers` już obsługuje dowolne pola na próg (endDate,
-- participantLimit) — nic tam nie trzeba zmieniać, znikają tylko kolumny
-- trybu.
ALTER TABLE event_distances DROP COLUMN IF EXISTS price_tier_mode;
ALTER TABLE event_distances DROP COLUMN IF EXISTS participant_count_type;

-- ── 2. `office_price` staje się Wariantem opłaty ────────────────────────────
-- Wariant oznaczony jako dostępny wyłącznie przy zapisie w biurze zawodów i
-- niewidoczny w formularzu publicznym — zamiast specjalnej kolumny na
-- Dystansie. `src/Http/routes.php` przestaje zaszywać `cena + 20`.
CREATE TABLE IF NOT EXISTS event_distance_pricing_variants (
  id VARCHAR(36) PRIMARY KEY,
  distance_id VARCHAR(36) NOT NULL,
  name VARCHAR(180) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  office_only TINYINT(1) NOT NULL DEFAULT 0,
  visible_in_public_form TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_event_distance_pricing_variants_distance (distance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @office_price_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'event_distances' AND column_name = 'office_price'
);
SET @backfill_office_variant_sql = IF(
  @office_price_exists > 0,
  'INSERT INTO event_distance_pricing_variants (id, distance_id, name, price, office_only, visible_in_public_form, sort_order)
   SELECT UUID(), d.id, ''Zapis w biurze zawodow'', d.office_price, 1, 0, 0
   FROM event_distances d
   WHERE d.office_price IS NOT NULL
     AND NOT EXISTS (
       SELECT 1 FROM event_distance_pricing_variants v
       WHERE v.distance_id = d.id AND v.office_only = 1
     )',
  'DO 0'
);
PREPARE office_variant_stmt FROM @backfill_office_variant_sql;
EXECUTE office_variant_stmt;
DEALLOCATE PREPARE office_variant_stmt;

ALTER TABLE event_distances DROP COLUMN IF EXISTS office_price;

-- ── 3. `insurance_settings` z jednego wiersza na katalog wariantów ──────────
-- Admin definiuje warianty NNW (nazwa + własne parametry składki), organizator
-- wybiera oferowane na wydarzeniu. Istniejący jeden wiersz dostaje nazwę i
-- zostaje pierwszą pozycją katalogu — nic z niego nie ginie.
ALTER TABLE insurance_settings
  ADD COLUMN IF NOT EXISTS name VARCHAR(180) NOT NULL DEFAULT '' AFTER id,
  ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1 AFTER info_html,
  ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER active;

UPDATE insurance_settings SET name = 'Podstawowe' WHERE name = '';

-- ── 4. Zapis niesie Rozliczenie zamiast trzech kolumn kwotowych ─────────────
-- `price_paid` / `discount_amount` / `insurance_paid` na registrations
-- znikają na rzecz pozycjonowanych linii Rozliczenia: nazwa, podstawa
-- naliczenia, kwota, znacznik czasu zamrożenia. Migrowane bezstratnie:
-- cena bazowa odtworzona jako price_paid + discount_amount (odwrotność wzoru
-- z RegistrationRepository::pricingForRegistration), rabat i ubezpieczenie
-- jako osobne pozycje — suma pozycji odpowiada temu, co realnie naliczono.
CREATE TABLE IF NOT EXISTS registration_settlement_items (
  id VARCHAR(36) PRIMARY KEY,
  registration_id VARCHAR(36) NOT NULL,
  name VARCHAR(180) NOT NULL,
  basis VARCHAR(60) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  frozen_at VARCHAR(64) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_registration_settlement_items_registration (registration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @reg_price_paid_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'registrations' AND column_name = 'price_paid'
);

SET @backfill_start_fee_sql = IF(
  @reg_price_paid_exists > 0,
  'INSERT INTO registration_settlement_items (id, registration_id, name, basis, amount, frozen_at, sort_order)
   SELECT UUID(), r.id, ''Oplata startowa'', ''distance_price'',
     ROUND(COALESCE(r.price_paid, 0) + COALESCE(r.discount_amount, 0), 2), r.registered_at, 0
   FROM registrations r
   WHERE r.price_paid IS NOT NULL
     AND NOT EXISTS (
       SELECT 1 FROM registration_settlement_items s
       WHERE s.registration_id = r.id AND s.basis = ''distance_price''
     )',
  'DO 0'
);
PREPARE start_fee_stmt FROM @backfill_start_fee_sql;
EXECUTE start_fee_stmt;
DEALLOCATE PREPARE start_fee_stmt;

SET @backfill_discount_sql = IF(
  @reg_price_paid_exists > 0,
  'INSERT INTO registration_settlement_items (id, registration_id, name, basis, amount, frozen_at, sort_order)
   SELECT UUID(), r.id,
     CONCAT(''Kod rabatowy'', IF(r.discount_code IS NOT NULL AND r.discount_code <> '''', CONCAT('' '', r.discount_code), '''')),
     ''discount_code'', -ABS(r.discount_amount), r.registered_at, 1
   FROM registrations r
   WHERE r.discount_amount IS NOT NULL AND r.discount_amount <> 0
     AND NOT EXISTS (
       SELECT 1 FROM registration_settlement_items s
       WHERE s.registration_id = r.id AND s.basis = ''discount_code''
     )',
  'DO 0'
);
PREPARE discount_stmt FROM @backfill_discount_sql;
EXECUTE discount_stmt;
DEALLOCATE PREPARE discount_stmt;

SET @backfill_insurance_sql = IF(
  @reg_price_paid_exists > 0,
  'INSERT INTO registration_settlement_items (id, registration_id, name, basis, amount, frozen_at, sort_order)
   SELECT UUID(), r.id, ''Ubezpieczenie NNW'', ''insurance'', r.insurance_paid, r.registered_at, 2
   FROM registrations r
   WHERE r.insurance_paid IS NOT NULL
     AND NOT EXISTS (
       SELECT 1 FROM registration_settlement_items s
       WHERE s.registration_id = r.id AND s.basis = ''insurance''
     )',
  'DO 0'
);
PREPARE insurance_stmt FROM @backfill_insurance_sql;
EXECUTE insurance_stmt;
DEALLOCATE PREPARE insurance_stmt;

ALTER TABLE registrations DROP COLUMN IF EXISTS price_paid;
ALTER TABLE registrations DROP COLUMN IF EXISTS discount_amount;
ALTER TABLE registrations DROP COLUMN IF EXISTS insurance_paid;
