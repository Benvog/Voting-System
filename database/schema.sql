-- VoteMS database schema (MySQL 8 / MariaDB 10.4+).
-- Safe to re-run: every table is created only if it is missing.

CREATE TABLE IF NOT EXISTS admins (
  id            INT          NOT NULL AUTO_INCREMENT,
  username      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS elections (
  id         INT          NOT NULL AUTO_INCREMENT,
  name       VARCHAR(120) NOT NULL,
  status     ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
  starts_at  DATETIME     DEFAULT NULL,
  ends_at    DATETIME     DEFAULT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_elections_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS positions (
  id          INT          NOT NULL AUTO_INCREMENT,
  election_id INT          NOT NULL,
  name        VARCHAR(100) NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_position_name_per_election (election_id, name),
  KEY idx_positions_election (election_id),
  CONSTRAINT fk_positions_election FOREIGN KEY (election_id) REFERENCES elections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS candidates (
  id          INT          NOT NULL AUTO_INCREMENT,
  position_id INT          NOT NULL,
  name        VARCHAR(120) NOT NULL,
  manifesto   TEXT         DEFAULT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_candidate_name_per_position (position_id, name),
  KEY idx_candidates_position (position_id),
  CONSTRAINT fk_candidates_position FOREIGN KEY (position_id) REFERENCES positions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voters (
  id            INT          NOT NULL AUTO_INCREMENT,
  voter_uid     VARCHAR(20)  NOT NULL,
  full_name     VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY voter_uid (voter_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per vote. The unique key is what guarantees one vote per voter per
-- position, even if two requests arrive at the same moment.
CREATE TABLE IF NOT EXISTS votes (
  id           BIGINT    NOT NULL AUTO_INCREMENT,
  election_id  INT       NOT NULL,
  position_id  INT       NOT NULL,
  voter_id     INT       NOT NULL,
  candidate_id INT       NOT NULL,
  cast_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_one_vote_per_voter_per_position (election_id, position_id, voter_id),
  KEY idx_votes_candidate (candidate_id),
  KEY idx_votes_position (position_id),
  KEY idx_votes_election (election_id),
  KEY idx_votes_voter (voter_id),
  CONSTRAINT fk_votes_candidate FOREIGN KEY (candidate_id) REFERENCES candidates (id) ON DELETE CASCADE,
  CONSTRAINT fk_votes_election  FOREIGN KEY (election_id)  REFERENCES elections (id)  ON DELETE CASCADE,
  CONSTRAINT fk_votes_position  FOREIGN KEY (position_id)  REFERENCES positions (id)  ON DELETE CASCADE,
  CONSTRAINT fk_votes_voter     FOREIGN KEY (voter_id)     REFERENCES voters (id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed logins, used to slow down PIN guessing. Rows older than a day are pruned.
CREATE TABLE IF NOT EXISTS login_attempts (
  id           BIGINT      NOT NULL AUTO_INCREMENT,
  ip           VARCHAR(45) NOT NULL,
  identifier   VARCHAR(50) NOT NULL,
  attempted_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_ip (ip, attempted_at),
  KEY idx_attempts_identifier (identifier, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
