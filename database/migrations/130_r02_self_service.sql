-- R-02 (spec 20 "Życie Zapisu po opłaceniu", zakres P2 - do potwierdzenia przez Zamawiającego):
-- samoobsługa Zapisu per Wydarzenie + historia zmian Zapisu.
--   * self_service_enabled  - przełącznik: czy właściciel Zapisu może sam zrezygnować (a w kolejnych
--     krokach R-02: zmienić Dystans). Domyślnie włączone; wyłączone = "tylko organizator".
--   * self_service_deadline - wspólna data graniczna samoobsługi. NULL = domyślnie 7 dni przed
--     startem Wydarzenia (liczone w aplikacji, SelfServicePolicy).
--   * registration_changes  - dziennik zmian jednego Zapisu widoczny dla zawodnika i organizatora.
--     `type` obejmuje już zmianę Dystansu i transfer (kolejne kroki R-02 tylko dopisują wiersze).
--
-- Przenośne i idempotentne (adnotacja @guard, CREATE TABLE IF NOT EXISTS).

-- @guard: column-missing events.self_service_enabled
ALTER TABLE events ADD COLUMN self_service_enabled TINYINT(1) NOT NULL DEFAULT 1;
-- @guard: column-missing events.self_service_deadline
ALTER TABLE events ADD COLUMN self_service_deadline DATETIME NULL;

CREATE TABLE IF NOT EXISTS registration_changes (
  id VARCHAR(36) PRIMARY KEY,
  registration_id VARCHAR(36) NOT NULL,
  type ENUM('cancelled','distance_changed','transferred') NOT NULL,
  from_json JSON NULL,
  to_json JSON NULL,
  actor_id VARCHAR(36) NULL,
  actor_role VARCHAR(32) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_registration_changes_registration (registration_id, created_at),
  CONSTRAINT fk_registration_changes_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
