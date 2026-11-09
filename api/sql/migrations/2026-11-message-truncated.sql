-- Migration : mails trop volumineux tronqués par le relais au lieu d'être refusés.
-- Pour une base créée AVANT cette version (table mailpit_messages sans colonne truncated).
-- Une nouvelle installation n'en a pas besoin : schema.sql contient déjà tout.
-- À importer une fois via phpMyAdmin (onglet Importer), après 2026-11-health-pings.sql.
-- Peut être rejouée sans risque.
--
-- MySQL ne connaît pas « ADD COLUMN IF NOT EXISTS » : la colonne n'est ajoutée que si
-- information_schema montre qu'elle manque encore. Les mails déjà enregistrés valent 0 (complets).

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE mailpit_messages ADD COLUMN truncated TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = corps (et en-têtes) raccourcis par le relais : mail trop volumineux pour max_body_size'' AFTER completed_at',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailpit_messages' AND COLUMN_NAME = 'truncated'
);
PREPARE migration FROM @sql;
EXECUTE migration;
DEALLOCATE PREPARE migration;
