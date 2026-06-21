CREATE TABLE IF NOT EXISTS insurance_settings (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    base_fee DECIMAL(8,2) NOT NULL DEFAULT 15.00,
    age_multiplier DECIMAL(8,4) NOT NULL DEFAULT 0.50,
    max_age INT NOT NULL DEFAULT 70,
    info_html TEXT NOT NULL DEFAULT '',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO insurance_settings (id, base_fee, age_multiplier, max_age, info_html)
VALUES (
    '00000000-0000-0000-0000-000000000001',
    15.00,
    0.50,
    70,
    '<p>Ubezpieczenie NNW (Następstwa Nieszczęśliwych Wypadków) chroni uczestnika na wypadek urazu doznanego podczas zawodów. Składka zależy od wieku uczestnika i jest płatna jednorazowo przy rejestracji.</p>'
);
