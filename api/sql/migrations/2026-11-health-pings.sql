-- Migration : pings de santé du relais (POST /v1/health) et page « Statut » du Dashboard.
-- Pour une base créée AVANT cette version (pas de table mailpit_health_pings).
-- Une nouvelle installation n'en a pas besoin : schema.sql contient déjà tout.
-- À importer une fois via phpMyAdmin (onglet Importer), après 2026-11-login-throttle-ip.sql.
-- Peut être rejouée sans risque.

-- Pings de santé du relais (POST /v1/health, toutes les 30 secondes) : page « Statut » du Dashboard.
-- Les lignes de plus de 48 heures sont supprimées à chaque ping.
CREATE TABLE IF NOT EXISTS mailpit_health_pings (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    received_at DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT 'Réception par l''API (UTC)',
    latency_ms  INT UNSIGNED    NULL     COMMENT 'Aller-retour HTTP du ping précédent, mesuré par le relais (ms). NULL = inconnu',
    pending     INT UNSIGNED    NULL     COMMENT 'Mails en attente dans Mailpit. NULL = Mailpit injoignable',
    failed      INT UNSIGNED    NULL     COMMENT 'Mails refusés par l''API (tag relay-failed). NULL = Mailpit injoignable',
    source_ip   VARCHAR(45)     NOT NULL COMMENT 'IP du relais',
    label       VARCHAR(255)    NULL     COMMENT 'En-tête Mailpit-Label (RELAY_LABEL)',
    PRIMARY KEY (id),
    KEY idx_received_at (received_at)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_0900_ai_ci;
