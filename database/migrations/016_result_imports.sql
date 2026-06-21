ALTER TABLE results
  ADD COLUMN IF NOT EXISTS registration_id VARCHAR(36) NULL AFTER user_id,
  ADD COLUMN IF NOT EXISTS source_import_batch_id VARCHAR(36) NULL AFTER created_at,
  ADD COLUMN IF NOT EXISTS source_import_row_id VARCHAR(36) NULL AFTER source_import_batch_id,
  ADD INDEX IF NOT EXISTS idx_results_registration (registration_id),
  ADD INDEX IF NOT EXISTS idx_results_import_batch (source_import_batch_id);

CREATE TABLE IF NOT EXISTS result_import_batches (
  id VARCHAR(36) PRIMARY KEY,
  event_id VARCHAR(36) NOT NULL,
  distance_id VARCHAR(36) NOT NULL,
  mode ENUM('replace','upsert') NOT NULL DEFAULT 'replace',
  status ENUM('uploaded','mapped','analyzed','committed') NOT NULL DEFAULT 'uploaded',
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(512) NOT NULL,
  file_url VARCHAR(512) NULL,
  file_mime_type VARCHAR(128) NOT NULL,
  sheet_name VARCHAR(180) NULL,
  headers JSON NOT NULL,
  mapping JSON NULL,
  summary JSON NULL,
  uploaded_by VARCHAR(36) NOT NULL,
  uploaded_at VARCHAR(64) NOT NULL,
  committed_at VARCHAR(64) NULL,
  INDEX idx_result_import_batches_event (event_id, distance_id),
  INDEX idx_result_import_batches_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS result_import_rows (
  id VARCHAR(36) PRIMARY KEY,
  batch_id VARCHAR(36) NOT NULL,
  row_number INT NOT NULL,
  raw_data JSON NOT NULL,
  mapped_data JSON NULL,
  candidates JSON NULL,
  selected_registration_id VARCHAR(36) NULL,
  selected_user_id VARCHAR(36) NULL,
  status ENUM('ready','needs_match','ambiguous','invalid','ignored','committed') NOT NULL DEFAULT 'invalid',
  errors JSON NULL,
  warnings JSON NULL,
  ignore_row TINYINT(1) NOT NULL DEFAULT 0,
  updated_at VARCHAR(64) NOT NULL,
  UNIQUE KEY uniq_result_import_row (batch_id, row_number),
  INDEX idx_result_import_rows_batch (batch_id),
  INDEX idx_result_import_rows_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
