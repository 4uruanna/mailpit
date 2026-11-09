<?php

/*
 * Configuration de l'API.
 *
 * Copiez ce fichier en « config.php » (même dossier) puis adaptez les valeurs.
 * « config.php » contient des mots de passe : il est ignoré par Git et
 * protégé par le .htaccess. Ne le rendez jamais accessible publiquement.
 */

return [
    // true uniquement pour déboguer : affiche le détail des erreurs dans les réponses
    // (JSON de l'API et pages d'erreur du Dashboard).
    'debug' => false,

    // Préfixe des URL si l'API n'est pas à la racine du domaine (ex. '/api').
    // Laisser vide pour https://api.amnesic.dev/v1/messages
    'base_path' => '',

    // HTTPS obligatoire : une page demandée en http:// est redirigée vers https:// (301), toute
    // autre requête en clair (POST, DELETE…) est refusée (403), et les réponses HTTPS portent
    // l'en-tête Strict-Transport-Security (un an). Activez le certificat SSL de l'hébergement AVANT.
    // false UNIQUEMENT pour un essai en local sans HTTPS (php -S, http://localhost…).
    'force_https' => true,

    // Fichier de log (erreurs PHP, requêtes refusées). null = log par défaut de l'hébergement.
    'log_file' => __DIR__ . '/var/log/api.log',

    // Rotation du log : à cette taille (en octets), api.log est renommé en api.log.1 (l'archive
    // précédente est remplacée) et un nouveau fichier repart de zéro. Vérifié à chaque requête.
    // 1 Mo minimum. 0 = jamais (le log grossit alors sans limite).
    'log_max_size' => 10 * 1024 * 1024,

    // Base MySQL (Espace client OVH > Hébergements > Bases de données).
    'db' => [
        'host'     => 'xxxxxx.mysql.db',
        'port'     => 3306,
        'name'     => 'nom_de_la_base',
        'user'     => 'utilisateur',
        'password' => 'mot_de_passe',
    ],

    'webhook' => [
        // Adresses autorisées à appeler l'API : celles du serveur Mailpit.
        // IPv4 ou IPv6, adresse seule ou plage CIDR (ex. '2001:db8:1:2::/64').
        // ⚠️ Si votre serveur a une IPv6, ajoutez-la aussi : Mailpit l'utilisera
        //    en priorité pour joindre api.amnesic.dev.
        // Liste vide = tout est refusé.
        'allowed_ips' => [
            '203.0.113.10',
            // '2001:db8::10',
        ],

        // Jeton secret OBLIGATOIRE (32 caractères minimum), identique à RELAY_API_TOKEN
        // dans /etc/mailpit/relay.env. Le relais l'envoie dans l'en-tête
        // « Authorization: Bearer … ». Il s'ajoute au filtre IP.
        // ⚠️ Il n'est accepté que dans cet en-tête : un « ?token=… » dans l'URL est refusé
        // (il apparaîtrait dans les logs d'accès). Mailpit ne peut donc pas appeler l'API
        // directement : passez par le relais.
        // Générez-le avec : openssl rand -hex 32
        'token' => 'REMPLACEZ_PAR_LE_RESULTAT_DE_openssl_rand_-hex_32',

        // Taille maximale du JSON d'un mail (en-têtes, texte, HTML), en octets.
        // Les pièces jointes n'en font pas partie : elles sont envoyées à part.
        'max_body_size' => 16 * 1024 * 1024,

        // Taille maximale d'UNE pièce jointe (ou image intégrée), en octets.
        // Une pièce plus grosse est décrite en base (nom, type, taille) mais son
        // contenu n'est pas enregistré (état « too_large »).
        // ⚠️ L'hébergement doit accepter des requêtes de cette taille (voir le README).
        'max_attachment_size' => 5 * 1024 * 1024,

        // Nombre de mails gardés : à chaque nouveau mail, les plus anciens au-delà de
        // cette limite sont supprimés (base ET fichiers). Même valeur que MP_MAX_MESSAGES
        // de Mailpit, pour garder les mêmes mails des deux côtés. 0 = sans limite.
        'max_messages' => 1000,

        // Les mails sont supprimés (base ET pièces jointes) ce nombre de jours après leur
        // réception par Mailpit. Mettez la même durée dans MP_MAX_AGE de Mailpit (ex. 7d).
        // Nettoyage automatique, au plus toutes les 15 minutes, après une requête du relais :
        // aucune tâche planifiée n'est nécessaire (voir « Rétention » dans le README).
        // 0 = jamais (seule la limite max_messages s'applique).
        'max_age_days' => 7,

        // Dossier des pièces jointes : un sous-dossier par mail.
        // Il doit être accessible en écriture par PHP, et jamais servi par le web.
        // Le nettoyage y retire aussi ce qu'un envoi interrompu ou un effacement raté a laissé.
        'storage_dir' => __DIR__ . '/var/messages',
    ],

    // Dashboard des administrateurs (https://api.amnesic.dev/v1/app/signin).
    // Les comptes sont dans la table mailpit_admins : voir « php bin/create-admin.php » dans le README.
    'app' => [
        // Déconnexion automatique après ce nombre de secondes d'inactivité.
        'session_lifetime' => 2 * 60 * 60,

        // Fuseau horaire d'affichage des dates (elles sont stockées en UTC).
        'timezone' => 'Europe/Paris',
    ],

    // À laisser vide sur un hébergement OVH classique : l'IP du client est dans REMOTE_ADDR.
    // Si un proxy / CDN est placé devant l'API, listez ses IP (ou plages CIDR) :
    // l'IP du client sera alors lue dans l'en-tête indiqué, uniquement pour les
    // requêtes qui viennent de ces proxys.
    // De même, X-Forwarded-Proto (http ou https, pour 'force_https') n'est lu que pour eux.
    // ⚠️ Un proxy qui joint l'hébergement en http:// doit être listé ici, sinon chaque page
    //    est redirigée vers https:// à l'infini.
    'proxy' => [
        'trusted_proxies' => [],
        'header'          => 'X-Forwarded-For',
    ],
];
