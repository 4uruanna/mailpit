-- Migration : limitation des connexions au Dashboard par identifiant ET par adresse IP.
-- Pour une base créée AVANT cette version (table mailpit_login_attempts sans colonne ip_key).
-- Une nouvelle installation n'en a pas besoin : schema.sql contient déjà tout.
-- À importer une fois via phpMyAdmin (onglet Importer), après 2026-11-dashboard-tokens.sql.
-- Peut être rejouée sans risque.
--
-- MySQL ne connaît ni « ADD COLUMN IF NOT EXISTS » ni « DROP INDEX IF EXISTS » : chaque
-- modification n'est donc lancée que si information_schema montre qu'elle manque encore.

-- Table complète, si elle n'existe pas encore.
CREATE TABLE IF NOT EXISTS mailpit_login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username     VARCHAR(255)    NOT NULL COMMENT 'Identifiant saisi, en minuscules (même s''il n''existe pas)',
    ip           VARCHAR(45)     NOT NULL,
    ip_key       VARCHAR(45)     NOT NULL COMMENT 'Adresse comptée : IPv4 exacte, ou préfixe /64 d''une IPv6',
    attempted_at DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_ip_key_date (ip_key, attempted_at),
    KEY idx_username_ip_key_date (username, ip_key, attempted_at),
    KEY idx_attempted_at (attempted_at)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_0900_ai_ci;

-- Colonne ip_key. Les tentatives déjà enregistrées reprennent leur adresse telle quelle : exact
-- pour une IPv4 ; une IPv6 n'est pas regroupée par /64, mais elle expire au bout de 30 minutes.
SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE mailpit_login_attempts ADD COLUMN ip_key VARCHAR(45) NOT NULL DEFAULT '''' COMMENT ''Adresse comptée : IPv4 exacte, ou préfixe /64 d''''une IPv6'' AFTER ip',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailpit_login_attempts' AND COLUMN_NAME = 'ip_key'
);
PREPARE migration FROM @sql;
EXECUTE migration;
DEALLOCATE PREPARE migration;

UPDATE mailpit_login_attempts SET ip_key = LEFT(ip, 45) WHERE ip_key = '';
ALTER TABLE mailpit_login_attempts ALTER COLUMN ip_key DROP DEFAULT;

-- Index par adresse (plafond tous identifiants confondus).
SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE mailpit_login_attempts ADD KEY idx_ip_key_date (ip_key, attempted_at)', 'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailpit_login_attempts' AND INDEX_NAME = 'idx_ip_key_date'
);
PREPARE migration FROM @sql;
EXECUTE migration;
DEALLOCATE PREPARE migration;

-- Index par couple identifiant + adresse.
SET @sql = (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE mailpit_login_attempts ADD KEY idx_username_ip_key_date (username, ip_key, attempted_at)', 'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailpit_login_attempts' AND INDEX_NAME = 'idx_username_ip_key_date'
);
PREPARE migration FROM @sql;
EXECUTE migration;
DEALLOCATE PREPARE migration;

-- L'ancien index par identifiant seul est couvert par le précédent.
SET @sql = (
    SELECT IF(COUNT(*) > 0, 'ALTER TABLE mailpit_login_attempts DROP KEY idx_username_date', 'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailpit_login_attempts' AND INDEX_NAME = 'idx_username_date'
);
PREPARE migration FROM @sql;
EXECUTE migration;
DEALLOCATE PREPARE migration;
