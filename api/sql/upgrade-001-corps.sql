-- Mise à jour d'une base créée avec une version précédente de schema.sql :
-- ajoute les colonnes du corps des mails (envoyé par le relais, dossier relay/).
-- À importer une seule fois via phpMyAdmin. Inutile si la table vient d'être créée avec schema.sql.

ALTER TABLE mailpit_messages
    ADD COLUMN text_body MEDIUMTEXT   NULL COMMENT 'Corps texte (envoyé par le relais)'               AFTER snippet,
    ADD COLUMN html_body MEDIUMTEXT   NULL COMMENT 'Corps HTML (envoyé par le relais)'                AFTER text_body,
    ADD COLUMN raw_file  VARCHAR(100) NULL COMMENT 'Fichier .eml dans raw_dir (envoyé par le relais)' AFTER html_body;
