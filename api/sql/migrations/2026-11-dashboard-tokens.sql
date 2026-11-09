-- Migration : Dashboard administrateurs et jetons développeurs.
-- Pour une base créée AVANT cette version (tables mailpit_messages et mailpit_parts déjà là).
-- Une nouvelle installation n'en a pas besoin : schema.sql contient déjà tout.
-- À importer une fois via phpMyAdmin (onglet Importer). Peut être rejouée sans risque.

-- Destinataires de chaque message (To, Cc, Bcc), en minuscules : permet de retrouver
-- rapidement les mails d'une adresse (jetons développeurs, GET /v1/messages?address=…).
CREATE TABLE IF NOT EXISTS mailpit_message_recipients (
    message_id BIGINT UNSIGNED NOT NULL COMMENT 'mailpit_messages.id',
    address    VARCHAR(320)    NOT NULL COMMENT 'Adresse e-mail, en minuscules',
    PRIMARY KEY (message_id, address),
    KEY idx_address (address, message_id),
    CONSTRAINT fk_recipients_message FOREIGN KEY (message_id) REFERENCES mailpit_messages (id) ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_0900_ai_ci;

-- Administrateurs du Dashboard (/v1/app). Mot de passe haché avec password_hash() :
-- voir « php bin/create-admin.php » dans le README.
CREATE TABLE IF NOT EXISTS mailpit_admins (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL COMMENT 'Haché (password_hash), jamais en clair',
    PRIMARY KEY (id),
    UNIQUE KEY uq_username (username)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_0900_ai_ci;

-- Échecs de connexion au Dashboard : au plus 3 par identifiant sur 30 minutes.
CREATE TABLE IF NOT EXISTS mailpit_login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username     VARCHAR(255)    NOT NULL COMMENT 'Identifiant saisi, en minuscules (même s''il n''existe pas)',
    ip           VARCHAR(45)     NOT NULL,
    attempted_at DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_username_date (username, attempted_at),
    KEY idx_attempted_at (attempted_at)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_0900_ai_ci;

-- Jetons des développeurs : ils lisent (et suppriment) les mails des adresses associées.
-- Seule l'empreinte SHA-256 du jeton est gardée : il n'est affiché qu'une fois, à sa création.
CREATE TABLE IF NOT EXISTS mailpit_tokens (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(255) NOT NULL                COMMENT 'Nom libre (projet, développeur…)',
    token_hash   CHAR(64)     NOT NULL                COMMENT 'SHA-256 du jeton, en hexadécimal',
    token_hint   VARCHAR(16)  NOT NULL                COMMENT 'Début du jeton, pour le reconnaître',
    created_at   DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_used_at DATETIME(6)  NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token_hash (token_hash)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_0900_ai_ci;

-- Adresses e-mail associées à chaque jeton.
CREATE TABLE IF NOT EXISTS mailpit_token_addresses (
    token_id   INT UNSIGNED NOT NULL COMMENT 'mailpit_tokens.id',
    address    VARCHAR(320) NOT NULL COMMENT 'Adresse e-mail, en minuscules',
    created_at DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (token_id, address),
    KEY idx_address (address),
    CONSTRAINT fk_token_addresses_token FOREIGN KEY (token_id) REFERENCES mailpit_tokens (id) ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_0900_ai_ci;

-- Remplit les destinataires des mails déjà enregistrés.
INSERT IGNORE INTO mailpit_message_recipients (message_id, address)
SELECT m.id, LOWER(TRIM(r.address))
FROM mailpit_messages m,
     JSON_TABLE(
         JSON_MERGE_PRESERVE(m.to_addresses, m.cc_addresses, m.bcc_addresses),
         '$[*]' COLUMNS (address VARCHAR(320) PATH '$.address')
     ) AS r
WHERE r.address IS NOT NULL AND TRIM(r.address) <> '';
