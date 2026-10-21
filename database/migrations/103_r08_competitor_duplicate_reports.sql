-- Report of a possible duplicate Competitor (Zawodnik) by a guardian / organizer.
-- The report goes to the Moderator/Admin queue (alongside the existing profile merge);
-- a report alone merges nothing. Statuses: open -> rejected (Moderator/Admin decision)
-- or merged (set by the profile merge). Rows disappear with the profile (ON DELETE CASCADE).
CREATE TABLE IF NOT EXISTS competitor_duplicate_reports (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    competitor_id VARCHAR(36) NOT NULL COMMENT 'Competitor (Zawodnik) the report originates from',
    duplicate_of_id VARCHAR(36) NOT NULL COMMENT 'Profile considered the duplicate',
    reported_by VARCHAR(36) NOT NULL COMMENT 'users.id of the reporter (no FK - history must outlive deleting the Account)',
    reporter_role VARCHAR(32) NOT NULL,
    note VARCHAR(1000) NOT NULL DEFAULT '',
    status ENUM('open','rejected','merged') NOT NULL DEFAULT 'open',
    reject_reason VARCHAR(500) NULL,
    resolved_by VARCHAR(36) NULL COMMENT 'users.id of the Moderator/Admin who rejected the report',
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_competitor_dup_reports_status (status, created_at),
    INDEX idx_competitor_dup_reports_competitor (competitor_id),
    INDEX idx_competitor_dup_reports_duplicate (duplicate_of_id),
    CONSTRAINT fk_competitor_dup_reports_competitor FOREIGN KEY (competitor_id) REFERENCES competitors (id) ON DELETE CASCADE,
    CONSTRAINT fk_competitor_dup_reports_duplicate FOREIGN KEY (duplicate_of_id) REFERENCES competitors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
