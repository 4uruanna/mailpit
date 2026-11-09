-- Mise à jour d'une base créée avec une version précédente de schema.sql :
-- retire la gestion des mails bruts (.eml), qui n'existe plus.
-- À importer une seule fois via phpMyAdmin, APRÈS upgrade-002-pieces-jointes.sql.
-- Inutile si les tables viennent d'être créées avec schema.sql.
--
-- Les fichiers .eml déjà enregistrés ne sont pas effacés par ce script : supprimez-les par FTP
-- (var/messages/<ID>/message.eml, et var/messages/<ID>.eml des toutes premières versions).

DELETE FROM mailpit_parts WHERE kind = 'raw';

ALTER TABLE mailpit_parts
    MODIFY COLUMN kind ENUM('attachment', 'inline') NOT NULL COMMENT 'inline = image intégrée au HTML (cid:…)',
    MODIFY COLUMN part_id VARCHAR(50) NOT NULL COMMENT 'PartID Mailpit (« 2 », « 1.3 »…)';

ALTER TABLE mailpit_messages DROP COLUMN raw_file;

-- Un mail qui n'attendait plus que son .eml est désormais complet.
UPDATE mailpit_messages m
SET completed_at = NOW(6)
WHERE completed_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM mailpit_parts p WHERE p.message_id = m.id AND p.status = 'pending');
