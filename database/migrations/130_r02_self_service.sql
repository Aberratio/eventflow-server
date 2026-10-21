-- Registration (Zapis) self-service per Event (Wydarzenie) + Registration change history:
-- Per-event switches and a change log:
--   * self_service_enabled  - switch: whether the owner of a Registration can cancel it themselves (and in later
--     steps change the Distance). Enabled by default; disabled = "organizer only".
--   * self_service_deadline - common cut-off date for self-service. NULL = by default 7 days before
--     the Event start (computed in the application, SelfServicePolicy).
--   * registration_changes  - change log of a single Registration, visible to the competitor and organizer.
--     `type` already covers Distance change and transfer (later steps only add rows).
--
-- Portable and idempotent (@guard annotation, CREATE TABLE IF NOT EXISTS).

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
