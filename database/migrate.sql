-- Upgrades an existing VoteMS database to the current schema.
-- Safe to re-run.
--   mysql -u root voting_db < database/migrate.sql

-- Voters log in with an existing ID such as a registration number
-- (CS/MK/0700/09/23), which can be longer than the old VOT-XXXXXX codes.
ALTER TABLE voters MODIFY voter_uid VARCHAR(40) NOT NULL;

-- Login throttling (added with the single login page).
CREATE TABLE IF NOT EXISTS login_attempts (
  id           BIGINT      NOT NULL AUTO_INCREMENT,
  ip           VARCHAR(45) NOT NULL,
  identifier   VARCHAR(50) NOT NULL,
  attempted_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_ip (ip, attempted_at),
  KEY idx_attempts_identifier (identifier, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
