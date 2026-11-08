-- Schéma MySQL 8.4 de l'API webhook Mailpit.
-- À importer une fois via phpMyAdmin (Espace client OVH > Bases de données > « Aller sur phpMyAdmin » > Importer).

CREATE TABLE IF NOT EXISTS mailpit_messages (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mailpit_id    VARCHAR(64)     NOT NULL                COMMENT 'ID du message dans Mailpit',
    message_id    VARCHAR(998)    NOT NULL DEFAULT ''     COMMENT 'En-tête Message-ID',
    from_name     VARCHAR(255)    NOT NULL DEFAULT '',
    from_address  VARCHAR(320)    NOT NULL DEFAULT '',
    to_addresses  JSON            NOT NULL                COMMENT '[{"name": "...", "address": "..."}]',
    cc_addresses  JSON            NOT NULL,
    bcc_addresses JSON            NOT NULL,
    reply_to      JSON            NOT NULL,
    subject       VARCHAR(998)    NOT NULL DEFAULT '',
    snippet       TEXT            NOT NULL                COMMENT 'Début du texte (250 caractères max côté Mailpit)',
    text_body     MEDIUMTEXT      NULL                    COMMENT 'Corps texte (envoyé par le relais)',
    html_body     MEDIUMTEXT      NULL                    COMMENT 'Corps HTML (envoyé par le relais)',
    raw_file      VARCHAR(100)    NULL                    COMMENT 'Fichier .eml dans raw_dir (envoyé par le relais)',
    tags          JSON            NOT NULL,
    size          BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT 'Taille totale en octets',
    attachments   INT UNSIGNED    NOT NULL DEFAULT 0      COMMENT 'Nombre de pièces jointes',
    smtp_username VARCHAR(255)    NULL                    COMMENT 'Utilisateur SMTP authentifié, le cas échéant',
    mailpit_label VARCHAR(255)    NULL                    COMMENT 'En-tête Mailpit-Label (option MP_LABEL)',
    created_at    DATETIME(6)     NOT NULL                COMMENT 'Réception par Mailpit (UTC)',
    received_at   DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT 'Réception par l''API (UTC)',
    source_ip     VARCHAR(45)     NOT NULL                COMMENT 'IP ayant appelé le webhook',
    payload       JSON            NOT NULL                COMMENT 'Corps brut du webhook',
    PRIMARY KEY (id),
    UNIQUE KEY uq_mailpit_id (mailpit_id),
    KEY idx_created_at (created_at),
    KEY idx_from_address (from_address)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_0900_ai_ci;
