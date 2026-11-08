<?php

/*
 * Configuration de l'API.
 *
 * Copiez ce fichier en « config.php » (même dossier) puis adaptez les valeurs.
 * « config.php » contient des mots de passe : il est ignoré par Git et
 * protégé par le .htaccess. Ne le rendez jamais accessible publiquement.
 */

return [
    // true uniquement pour déboguer : affiche le détail des erreurs dans les réponses.
    'debug' => false,

    // Préfixe des URL si l'API n'est pas à la racine du domaine (ex. '/api').
    // Laisser vide pour https://api.amnesic.dev/hook/mailpit
    'base_path' => '',

    // Fichier de log (erreurs PHP, requêtes refusées). null = log par défaut de l'hébergement.
    'log_file' => __DIR__ . '/var/log/api.log',

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

        // Jeton secret facultatif (recommandé), à mettre dans l'URL du webhook :
        //   MP_WEBHOOK_URL=https://api.amnesic.dev/hook/mailpit?token=LE_JETON
        // Mailpit ne signe pas ses requêtes : ce jeton s'ajoute au filtre IP.
        // Générez-le avec : openssl rand -hex 24   (null = désactivé)
        'token' => null,

        // Taille maximale acceptée pour le corps de la requête (octets).
        'max_body_size' => 1024 * 1024,
    ],

    // À laisser vide sur un hébergement OVH classique : l'IP du client est dans REMOTE_ADDR.
    // Si un proxy / CDN est placé devant l'API, listez ses IP (ou plages CIDR) :
    // l'IP du client sera alors lue dans l'en-tête indiqué, uniquement pour les
    // requêtes qui viennent de ces proxys.
    'proxy' => [
        'trusted_proxies' => [],
        'header'          => 'X-Forwarded-For',
    ],
];
