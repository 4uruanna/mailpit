-- Mise à jour d'une base créée avec une version précédente de schema.sql :
-- ajoute les en-têtes, l'état « complet » des mails et la table des pièces jointes.
-- À importer une seule fois via phpMyAdmin, APRÈS upgrade-001-corps.sql.
-- Inutile si les tables viennent d'être créées avec schema.sql.

ALTER TABLE mailpit_messages
    ADD COLUMN headers      JSON         NULL                COMMENT 'Tous les en-têtes : {"Nom": ["valeur", …]} (envoyé par le relais)' AFTER html_body,
    ADD COLUMN return_path  VARCHAR(320) NOT NULL DEFAULT '' COMMENT 'En-tête Return-Path'                                              AFTER headers,
    ADD COLUMN message_date DATETIME(6)  NULL                COMMENT 'En-tête Date du mail (UTC)'                                       AFTER return_path,
    ADD COLUMN completed_at DATETIME(6)  NULL                COMMENT 'Toutes les pièces annoncées sont enregistrées (UTC). NULL = mail incomplet',
    ADD KEY idx_completed_at (completed_at);

-- Les mails déjà enregistrés n'attendent aucune pièce : ils sont complets.
UPDATE mailpit_messages SET completed_at = received_at WHERE completed_at IS NULL;

CREATE TABLE IF NOT EXISTS mailpit_parts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id   BIGINT UNSIGNED NOT NULL               COMMENT 'mailpit_messages.id',
    part_id      VARCHAR(50)     NOT NULL               COMMENT 'PartID Mailpit (« 2 », « 1.3 »…) ou « raw » pour le mail brut',
    kind         ENUM('attachment', 'inline', 'raw') NOT NULL COMMENT 'inline = image intégrée au HTML (cid:…)',
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
