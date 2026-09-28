-- E2.1: Import wynikow CSV z dopasowaniem po numerze startowym + reczne
-- dopasowanie niesparowanych.
--
-- Rozstrzygniecie: zc-second-brain/specyfikacje/.../14-wyniki-live-rekordy.md,
-- sekcje 1-2. Trzy zmiany schematu, ktore ten rozjazd wymaga:
--
-- 1. `status` traci trzeci brakujacy wariant: DNS ("nie wystartowal") jest
--    wartoscia statusu wyniku, nie brakiem wiersza, tak samo jak juz obsluzone
--    DNF/DSQ - byl pominiety w pierwszej wersji ENUM-a.
-- 2. Czas brutto/netto jako dwa osobne pola (netto opcjonalne - nie kazdy
--    pomiar ma mate startowa) z dokladnoscia do setnej sekundy - `time`/
--    `time_in_seconds` zostaja czasem BRUTTO (bez zmiany nazwy, zeby nie
--    lamac istniejacych odbiorcow), `time_in_seconds` zmienia typ na
--    DECIMAL(10,2) zeby pomiescic setne, i dochodzi para net_time/
--    net_time_in_seconds.
-- 3. Miedzyczasy (punkty kontrolne) - opcjonalna lista par nazwa+czas
--    dolaczana przy imporcie, gdy plik CSV je zawiera. Osobna tabela, bo to
--    lista o zmiennej dlugosci per wynik, nie kolumna.
--
-- Migracje nie maja ledgera i uruchamiaja sie przy kazdym starcie (patrz
-- Migrator.php) - MODIFY/ADD COLUMN IF NOT EXISTS/CREATE TABLE IF NOT EXISTS
-- sa tu bezpieczne do powtorzenia.

ALTER TABLE results MODIFY COLUMN status ENUM('finished','dnf','dns','dsq') NOT NULL DEFAULT 'finished';

ALTER TABLE results MODIFY COLUMN time_in_seconds DECIMAL(10,2) NOT NULL;

-- @guard: column-missing results.net_time
ALTER TABLE results ADD COLUMN net_time VARCHAR(32) NULL AFTER time;
-- @guard: column-missing results.net_time_in_seconds
ALTER TABLE results ADD COLUMN net_time_in_seconds DECIMAL(10,2) NULL AFTER time_in_seconds;

CREATE TABLE IF NOT EXISTS result_splits (
  id VARCHAR(36) PRIMARY KEY,
  result_id VARCHAR(36) NOT NULL,
  label VARCHAR(120) NOT NULL,
  time_seconds DECIMAL(10,2) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_result_splits_result (result_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
