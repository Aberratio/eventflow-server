-- R-08 (spec 22): zgłoszenie możliwego duplikatu Zawodnika przez opiekuna / organizatora.
-- Zgłoszenie trafia do kolejki Moderatora/Admina (obok istniejącego scalania profili);
-- samo zgłoszenie niczego nie scala. Wiersze znikają razem z profilem (ON DELETE CASCADE).
CREATE TABLE IF NOT EXISTS competitor_duplicate_reports (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    competitor_id VARCHAR(36) NOT NULL COMMENT 'Zawodnik, od którego wychodzi zgłoszenie',
    duplicate_of_id VARCHAR(36) NOT NULL COMMENT 'Profil uznany za duplikat',
    reported_by VARCHAR(36) NOT NULL COMMENT 'users.id zgłaszającego (bez FK - historia ma przeżyć usunięcie Konta)',
    reporter_role VARCHAR(32) NOT NULL,
    note VARCHAR(1000) NOT NULL DEFAULT '',
    status ENUM('open','dismissed','merged') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_competitor_dup_reports_status (status, created_at),
    INDEX idx_competitor_dup_reports_competitor (competitor_id),
    INDEX idx_competitor_dup_reports_duplicate (duplicate_of_id),
    CONSTRAINT fk_competitor_dup_reports_competitor FOREIGN KEY (competitor_id) REFERENCES competitors (id) ON DELETE CASCADE,
    CONSTRAINT fk_competitor_dup_reports_duplicate FOREIGN KEY (duplicate_of_id) REFERENCES competitors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
