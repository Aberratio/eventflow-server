CREATE TABLE IF NOT EXISTS participant_authorizations (
  id VARCHAR(36) PRIMARY KEY,
  grantor_id VARCHAR(36) NOT NULL COMMENT 'Uczestnik, który udziela upoważnienia',
  grantee_id VARCHAR(36) NOT NULL COMMENT 'Osoba upoważniona do zapisywania',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_grantor_grantee (grantor_id, grantee_id),
  INDEX idx_auth_grantor (grantor_id),
  INDEX idx_auth_grantee (grantee_id),
  CONSTRAINT fk_auth_grantor FOREIGN KEY (grantor_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_auth_grantee FOREIGN KEY (grantee_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
