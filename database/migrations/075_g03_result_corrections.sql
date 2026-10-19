-- G-03 (spec 14 pkt 6): korekta wyniku zostawia trwaly slad - kto, kiedy, z jakiej na jaka wartosc
-- (czas brutto/netto, status, miejsca), opcjonalny powod. Osobna tabela, a nie activity_logs,
-- bo dziennik aktywnosci jest czyszczony po okresie retencji (AdminRepository::pruneExpiredActivityLogs),
-- a historia korekt wyniku ma zyc tak dlugo jak wynik. Bez FK do results: slad zostaje
-- takze po usunieciu wyniku. Idempotentne (CREATE TABLE IF NOT EXISTS).
CREATE TABLE IF NOT EXISTS result_corrections (
  id VARCHAR(36) PRIMARY KEY,
  result_id VARCHAR(36) NOT NULL,
  event_id VARCHAR(36) NOT NULL,
  corrected_by VARCHAR(36) NOT NULL,
  corrected_at VARCHAR(64) NOT NULL,
  reason VARCHAR(500) NULL,
  was_official TINYINT(1) NOT NULL DEFAULT 0,
  changes JSON NOT NULL,
  INDEX idx_result_corrections_result (result_id, corrected_at),
  INDEX idx_result_corrections_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
