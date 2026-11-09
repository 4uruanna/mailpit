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
    headers       JSON            NULL                    COMMENT 'Tous les en-têtes : {"Nom": ["valeur", …]} (envoyé par le relais)',
    return_path   VARCHAR(320)    NOT NULL DEFAULT ''     COMMENT 'En-tête Return-Path',
    message_date  DATETIME(6)     NULL                    COMMENT 'En-tête Date du mail (UTC)',
    tags          JSON            NOT NULL,
    size          BIGINT UNSIGNED NOT NULL DEFAULT 0      COMMENT 'Taille totale en octets',
    attachments   INT UNSIGNED    NOT NULL DEFAULT 0      COMMENT 'Nombre de pièces jointes',
    smtp_username VARCHAR(255)    NULL                    COMMENT 'Utilisateur SMTP authentifié, le cas échéant',
    mailpit_label VARCHAR(255)    NULL                    COMMENT 'En-tête Mailpit-Label (option MP_LABEL)',
    created_at    DATETIME(6)     NOT NULL                COMMENT 'Réception par Mailpit (UTC)',
    received_at   DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT 'Réception par l''API (UTC)',
    source_ip     VARCHAR(45)     NOT NULL                COMMENT 'IP ayant appelé le webhook',
    payload       JSON            NOT NULL                COMMENT 'Corps brut du webhook (sans Text, HTML ni Headers)',
    completed_at  DATETIME(6)     NULL                    COMMENT 'Toutes les pièces annoncées sont enregistrées (UTC). NULL = mail incomplet',
    PRIMARY KEY (id),
    UNIQUE KEY uq_mailpit_id (mailpit_id),
    KEY idx_created_at (created_at),
    KEY idx_from_address (from_address),
    KEY idx_completed_at (completed_at)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_0900_ai_ci;

-- Pièces jointes et images intégrées au HTML de chaque message.
-- Le contenu est dans un fichier (storage_dir/<mailpit_id>/…), pas dans la base.
CREATE TABLE IF NOT EXISTS mailpit_parts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id   BIGINT UNSIGNED NOT NULL               COMMENT 'mailpit_messages.id',
    part_id      VARCHAR(50)     NOT NULL               COMMENT 'PartID Mailpit (« 2 », « 1.3 »…)',
    kind         ENUM('attachment', 'inline') NOT NULL  COMMENT 'inline = image intégrée au HTML (cid:…)',
    file_name    VARCHAR(255)    NOT NULL DEFAULT ''    COMMENT 'Nom de fichier d''origine',
    content_type VARCHAR(255)    NOT NULL DEFAULT '',
    content_id   VARCHAR(255)    NOT NULL DEFAULT ''    COMMENT 'Content-ID, référencé par cid:… dans le HTML',
    size         BIGINT UNSIGNED NOT NULL DEFAULT 0     COMMENT 'Taille en octets',
    status       ENUM('pending', 'stored', 'too_large') NOT NULL DEFAULT 'pending'
                                                        COMMENT 'pending = annoncée, contenu pas encore reçu ; too_large = au-delà de la limite, non enregistrée',
    sha256       CHAR(64)        NULL                   COMMENT 'Empreinte du contenu enregistré',
    storage_path VARCHAR(255)    NULL                   COMMENT 'Fichier, relatif à storage_dir',
    stored_at    DATETIME(6)     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_message_part (message_id, part_id),
    KEY idx_status (status),
    CONSTRAINT fk_parts_message FOREIGN KEY (message_id) REFERENCES mailpit_messages (id) ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_0900_ai_ci;
