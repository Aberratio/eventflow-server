-- Partia kodow rabatowych to generator, nie drugi mechanizm. Kody nadal leza
-- w event_discount_codes (limit uzyc = 1 to kod jednorazowy); partia jedynie grupuje je
-- pod wspolna etykieta, zeby dalo sie je wylistowac i wyeksportowac razem.
CREATE TABLE IF NOT EXISTS event_discount_code_batches (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    event_id VARCHAR(36) NOT NULL,
    label VARCHAR(120) NOT NULL,
    prefix VARCHAR(20) NOT NULL DEFAULT '',
    code_count INT NOT NULL,
    discount_type ENUM('percent','fixed') NOT NULL,
    discount_value DECIMAL(10,2) NOT NULL,
    max_uses INT NOT NULL DEFAULT 1,
    valid_from VARCHAR(64) NULL,
    valid_to VARCHAR(64) NULL,
    created_by VARCHAR(36) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_discount_code_batches_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @guard: column-missing event_discount_codes.batch_id
ALTER TABLE event_discount_codes ADD COLUMN batch_id VARCHAR(36) NULL;

-- @guard: index-missing event_discount_codes.idx_event_discount_codes_batch
ALTER TABLE event_discount_codes ADD INDEX idx_event_discount_codes_batch (batch_id);
