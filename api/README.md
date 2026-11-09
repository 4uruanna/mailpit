# API webhook Mailpit

Petite API **PHP 8.5 / Slim 4 / MySQL 8.4** pour un hébergement **OVH Perso**. Elle reçoit chaque mail arrivé dans Mailpit et l'enregistre : le message en base MySQL, les pièces jointes en fichiers.

Elle comprend aussi :

- un **Dashboard** pour les administrateurs (`/v1/app/…`) : liste de tous les mails, gestion des jetons développeurs, état de la connexion du relais (page **Statut**) ;
- des **jetons développeurs** : chacun lit (et supprime) par l'API les mails des adresses qui lui sont associées.

```
relais (serveur Mailpit) ── 1. POST /v1/messages                     mail en JSON ──► table mailpit_messages
        Authorization:  ◄── réponse : pièces attendues ("missing")
        Bearer <jeton>  ── 2. POST /v1/messages/{id}/part/{partId}   une requête par pièce ──► var/messages/{id}/…
                        ◄── réponse : "complete": true quand tout est enregistré  + table mailpit_parts
                        ── POST /v1/health toutes les 30 s   ping de santé ──► table mailpit_health_pings (page Statut)

développeurs ── GET /v1/messages, GET /v1/messages/{id}…  jeton « mpt_… » créé dans le Dashboard
administrateurs ── https://api.amnesic.dev/v1/app/signin  Dashboard (identifiant + mot de passe)
```

Trois sortes d'accès :

| Qui | Comment | Peut |
|---|---|---|
| **Relais** | IP dans `webhook.allowed_ips` (sinon `403`) **et** jeton `webhook.token` (sinon `401`). Ce jeton est configuré des deux côtés : dans `config.php` ici, et dans `RELAY_API_TOKEN` côté relais | **Seul** à pouvoir envoyer des mails et des pings de santé (`POST`). Peut aussi lire tous les mails (`GET /v1/messages` et `GET /v1/messages/{id}`) |
| **Développeurs** | Jeton `mpt_…` créé dans le Dashboard, depuis n'importe quelle IP | Lire et supprimer les mails des adresses associées à son jeton |
| **Administrateurs** | Identifiant et mot de passe (table `mailpit_admins`), depuis n'importe quelle IP. Sur 30 minutes : 3 essais par identifiant depuis une même adresse, 10 par adresse tous identifiants confondus | Voir tous les mails, créer et supprimer les jetons, leur associer des adresses |

Le mail complet est envoyé par le [relais](../relay/README.md), installé sur le serveur Mailpit ([Partie 7 du tutoriel](../docs/mailpit/07-relay.md)) : en-têtes, texte, HTML, pièces jointes et images intégrées. Le relais ne garde rien de son côté : il ne considère un mail comme livré que lorsque l'API répond `"complete": true`.

> ⚠️ Le relais est le **seul** moyen d'alimenter l'API. Le webhook **direct** de Mailpit (sans relais) est refusé (`401`) : Mailpit ne sait pas ajouter d'en-tête, et l'API n'accepte le jeton que dans l'en-tête `Authorization`, jamais dans l'URL, où il apparaîtrait dans les logs d'accès de l'hébergement. Voir l'[étape 5](#5-configurer-mailpit).

> 🔒 Tout passe en **HTTPS** : une page demandée en `http://` est redirigée vers `https://`, et toute autre requête en clair (`POST`, `DELETE`…) est refusée (`403`). Voir [HTTPS obligatoire](#https-obligatoire).

## Arborescence

```
api/
├── .htaccess            # redirige vers HTTPS, puis tout vers public/, et bloque les fichiers sensibles
├── .ovhconfig           # version de PHP sur l'hébergement OVH
├── config.example.php   # modèle de configuration → à copier en config.php
├── composer.json / composer.lock
├── phpunit.xml.dist     # configuration des tests automatisés (PHPUnit)
├── bin/
│   ├── create-admin.php # prépare la requête SQL qui crée un administrateur
│   └── cleanup.php      # lance le nettoyage à la main (facultatif, voir « Rétention »)
├── public/
│   ├── .htaccess        # HTTPS (redirection, HSTS) et contrôleur frontal Slim
│   └── index.php
├── sql/
│   ├── schema.sql       # tables MySQL, à importer une fois
│   └── migrations/      # mises à jour d'une base créée avec une version précédente
├── src/                 # code de l'API
├── templates/           # pages HTML du Dashboard (dont error.php : pages d'erreur, status.php : page Statut)
├── tests/               # tests automatisés (voir « Tests automatisés »), inutiles sur l'hébergement
├── var/log/             # logs (accessible en écriture) : api.log, son archive api.log.1
│                        # (rotation à 10 Mo) et api.log.lock (verrou de la rotation)
├── var/messages/        # pièces jointes et images intégrées, un dossier par mail (accessible en écriture),
│                        # et .maintenance : date du dernier nettoyage
└── vendor/              # généré par composer install, à envoyer en FTP
```

## Routes

### Relais

Elles exigent le jeton du relais, dans l'en-tête `Authorization: Bearer <jeton>` **uniquement**. Un `?token=<jeton>` dans l'URL est ignoré : sans en-tête valide, la réponse est `401`, et un avertissement est écrit dans `var/log/api.log` (sans la valeur du jeton). Et l'appel doit venir d'une IP de `webhook.allowed_ips`.

| Méthode | URL | Réponse |
|---|---|---|
| `POST` | `/v1/messages` | Corps : le mail en JSON (voir ci-dessous).<br>`201 {"status":"created", "id":"…", "complete":false, "missing":["2","1.3"], "too_large":["4"]}` : message enregistré<br>`200 {"status":"updated"\|"duplicate", …}` : déjà reçu (même ID Mailpit), complété si besoin<br>`413 {"error":"…", "max_body_size":16777216}` : plus gros que la limite (en octets), le relais renvoie une version **tronquée** (voir [Mails trop volumineux](#mails-trop-volumineux--tronqués-par-le-relais))<br>`400` JSON invalide · `415` pas du JSON · `422` champ invalide |
| `POST` | `/v1/messages/{id}/part/{partId}` | Corps : le contenu brut de la pièce (`Content-Type: application/octet-stream`), avec l'en-tête `X-Content-SHA256` (empreinte SHA-256 en hexadécimal).<br>`201 {"status":"stored", "complete":true, "missing":[], …}` : pièce enregistrée<br>`200 {"status":"already-stored", …}` : déjà reçue<br>`404` message ou pièce inconnus (ou mail supprimé pendant l'envoi : la pièce reçue est effacée) · `409` pièce `too_large`, ou déjà reçue avec un autre contenu · `413` au-delà de la limite · `415` mauvais Content-Type · `422` empreinte absente ou différente |
| `POST` | `/v1/health` | Ping de santé, envoyé par le relais toutes les 30 secondes (voir [Page Statut](#page-statut)). Corps JSON, chaque champ entier ou `null` : `{"latency_ms":85, "pending":0, "failed":2}` (latence du ping précédent en ms, mails en attente et refusés dans Mailpit), 4 Ko au plus. En-tête `Mailpit-Label` facultatif.<br>`200 {"status":"ok", "time":"2026-11-09T08:31:20.474Z"}` : ping enregistré (heure de l'API, UTC)<br>`400` JSON invalide · `413` trop volumineux · `415` pas du JSON · `422` champ invalide (nombre négatif, décimal, texte…) |

Pour les trois : `403` IP non autorisée (vérifiée en premier) · `401` jeton absent ou faux · `500` erreur serveur (base injoignable…).

Après une requête acceptée du relais (un mail, une pièce ou un ping de santé), l'API fait son [nettoyage](#rétention--7-jours-et-1000-mails-au-plus) si le dernier date de plus de 15 minutes. La réponse n'en dépend pas : une erreur de nettoyage est seulement écrite dans le log.

Pour toutes les routes (relais, développeurs, Dashboard) : en `http://`, `301` vers la même URL en `https://` pour `GET` et `HEAD`, `403 {"error":"HTTPS obligatoire : renvoyez la requête en https://"}` pour les autres méthodes, avant toute autre vérification. Voir [HTTPS obligatoire](#https-obligatoire).

### Développeurs

Elles exigent un jeton développeur, créé dans le Dashboard : en-tête `Authorization: Bearer mpt_…` (jamais dans l'URL). Le jeton ne voit que les mails dont l'un des destinataires (`To`, `Cc` ou `Bcc`) est une des adresses qui lui sont associées. Détail et exemples : [Jetons développeurs](#jetons-développeurs).

| Méthode | URL | Réponse |
|---|---|---|
| `GET` | `/v1/messages` | Les mails de ses adresses, du plus récent au plus ancien, en **résumé** (adresses, sujet, extrait, dates, pièces jointes), **sans leur corps**. 5 par défaut.<br>Paramètres : `address`, `limit` (25 au maximum), `offset` ou `page`.<br>`200 {"total":…, "count":…, "limit":5, "offset":0, "page":1, "messages":[…]}`<br>`400` paramètre invalide · `403` adresse non associée au jeton |
| `GET` | `/v1/messages/{id}` | Un mail **complet** : les champs de la liste, plus son corps (`text`, `html`) et ses en-têtes (`headers`).<br>`200 {"id":"…", …, "text":"…", "html":"…", "headers":{…}}` · `404` mail inconnu, ou reçu par aucune de ses adresses |
| `DELETE` | `/v1/messages/{id}` | Supprime ce mail.<br>`200 {"id":"…", "deleted":true}` · `404` mail inconnu, ou reçu par aucune de ses adresses |
| `GET` | `/v1/addresses` | Ses adresses et leur nombre de mails.<br>`200 {"count":2, "addresses":[{"address":"alice@test.mondomaine.fr", "messages":3}, …]}` |
| `DELETE` | `/v1/addresses?address=…` | Supprime tous les mails reçus par cette adresse (elle reste associée au jeton).<br>`200 {"address":"…", "deleted":3}` · `400` `address` absent · `403` adresse non associée au jeton |

Pour toutes : `401` jeton absent, inconnu ou supprimé.

`GET /v1/messages` et `GET /v1/messages/{id}` acceptent aussi le **jeton du relais**, depuis les IP de `webhook.allowed_ips` uniquement (sinon `403`) : il voit alors tous les mails. Pratique pour les tests depuis le serveur Mailpit ([Partie 8](../docs/mailpit/08-tests-relay-api.md)).

### Dashboard

Pages HTML pour les administrateurs, dans un navigateur. Voir [Dashboard](#dashboard-1).

| URL | Page |
|---|---|
| `/v1/app/signin` | Connexion |
| `/v1/app/messages` | Tous les mails, 50 par page. Filtres : adresses destinataires (`?address[]=…`, plusieurs possibles), ordre (`?sort=asc` : du plus ancien au plus récent), page (`?page=2`) |
| `/v1/app/messages/{id}` | Un mail, en onglets : **Mail** (par défaut ; version texte, `?view=html` pour le HTML), **Pièces jointes** (`?tab=attachments`), **En-têtes** (`?tab=headers`) |
| `/v1/app/messages/{id}/html` | Corps HTML du mail seul, isolé (affiché dans la page précédente) |
| `/v1/app/messages/{id}/parts/{partId}` | Téléchargement d'une pièce jointe ou d'une image intégrée |
| `/v1/app/messages/delete` (`POST`) | Supprime les mails cochés dans la liste |
| `/v1/app/messages/{id}/delete` (`POST`) | Supprime un mail |
| `/v1/app/dashboard.js` | Script des pages (confirmations, sélection), servi depuis `templates/` |
| `/v1/app/tokens` | Liste des jetons, création, suppression |
| `/v1/app/tokens/{id}` | Détail d'un jeton : associer et retirer des adresses |
| `/v1/app/status` | **Statut** : connexion du relais sur les dernières 24 heures (voir [Page Statut](#page-statut)) |

Toute autre URL renvoie `404`. Les erreurs (`404`, `405`, `500`…) ont deux formes, selon l'URL :

| URL | Réponse d'erreur |
|---|---|
| `/v1/app` et tout ce qui est en dessous, même une URL inconnue | Page HTML du Dashboard (`templates/error.php`), avec le bon code : « Page introuvable » ou le message précis (« Mail inconnu. », « Jeton inconnu. »), « Méthode non autorisée », « Erreur interne ». Lien **Retour aux mails**. Voir [Dashboard](#dashboard-1) |
| Toutes les autres (`/v1/messages`, `/v1/addresses`, URL inconnues…) | JSON, comme avant : `{"error":"…"}` pour les erreurs prévues par une route, sinon `{"message":"404 Not Found"}` (ou `405 Method Not Allowed`, `500 Internal Server Error`) |

**Champs envoyés par le relais**, en plus du résumé de Mailpit (`ID`, `MessageID`, `From`, `To`, `Cc`, `Bcc`, `ReplyTo`, `Subject`, `Created`, `Size`, `Attachments`, `Tags`, `Snippet`…) :

| Champ | Enregistré dans |
|---|---|
| `Text`, `HTML` | colonnes `text_body`, `html_body` |
| `Headers` (`{"Nom": ["valeur", …]}`) | colonne `headers` |
| `ReturnPath`, `Date` | colonnes `return_path`, `message_date` |
| `Truncated` (`true`, facultatif) | colonne `truncated` (`1`) : version raccourcie par le relais, le mail complet dépassait `webhook.max_body_size`. Autre chose qu'un booléen : `422` |
| `Parts` : `[{"PartID":"2", "Kind":"attachment", "FileName":"devis.pdf", "ContentType":"application/pdf", "ContentID":"", "Size":123456}, …]` | table `mailpit_parts` (une ligne par pièce). `Kind` vaut `attachment` (pièce jointe) ou `inline` (image intégrée au HTML, `cid:…`) |

La colonne `payload` garde le JSON reçu **sans** `Text`, `HTML` ni `Headers`, pour ne pas les stocker deux fois.

**Pièces jointes.** Le JSON ne contient que leur description. L'API répond avec la liste `missing` des pièces dont elle attend le contenu, que le relais envoie ensuite une par une (`POST /v1/messages/{id}/part/{partId}`). Chaque fichier est vérifié (SHA-256), écrit sur le disque, puis la ligne de `mailpit_parts` passe à l'état `stored`. Le message est **complet** (colonne `completed_at` remplie, `"complete": true`) quand plus aucune pièce n'est attendue.

Une pièce plus grosse que `webhook.max_attachment_size` (**5 Mo** par défaut) n'est pas attendue : sa ligne est enregistrée avec l'état `too_large` (nom, type, taille), sans contenu, et elle est listée dans `too_large`.

### Mails trop volumineux : tronqués par le relais

Le JSON d'un mail (texte + HTML + en-têtes, **sans** les pièces jointes, envoyées à part) ne doit pas dépasser `webhook.max_body_size` (**16 Mo** par défaut). Au-delà, l'API répond `413` avec sa limite :

```json
{"error": "corps de requête trop volumineux", "max_body_size": 16777216}
```

Le mail n'est plus perdu : le relais renvoie aussitôt une **version tronquée** qui tient dans cette limite (moins 1 Ko de marge). Il raccourcit d'abord le HTML, puis le texte, puis si besoin les valeurs d'en-têtes les plus longues, sans jamais couper un caractère UTF-8. Il ajoute à la fin du texte (et du HTML) la note `[… contenu tronqué par le relais : mail trop volumineux pour l'API]`, et le champ `"Truncated": true`. Au plus 3 versions tronquées : si l'API répond encore `413`, c'est un refus définitif (tag `relay-failed`). Détail : [relais](../relay/README.md#mails-trop-volumineux).

| Où | Ce qu'on voit |
|---|---|
| Base | colonne `mailpit_messages.truncated` à `1` |
| API (`GET /v1/messages`, `GET /v1/messages/{id}`) | `"truncated": true` |
| Dashboard | badge **tronqué** dans la liste et sur la page du mail, avec une phrase d'explication |
| Logs du relais | `mail … trop volumineux pour l'API (… octets, limite … octets) : envoi d'une version tronquée de … octets (essai 1/3)` |

**Même mail reçu deux fois** (ex. `max_body_size` augmenté, puis mail relancé depuis Mailpit) : la version complète l'emporte toujours. Elle remplace le corps d'une version tronquée et remet `truncated` à `0` ; une version tronquée arrivée après la version complète est ignorée. Un résumé seul, sans corps (ancien webhook direct de Mailpit), ne change pas `truncated`.

> 💡 Le mail complet reste dans Mailpit (jusqu'à `MP_MAX_AGE`). Pour éviter de tronquer, augmentez `webhook.max_body_size` dans `config.php` ; MySQL accepte jusqu'à 16 Mo par corps (colonnes `MEDIUMTEXT`).

**Hébergement qui vide les gros corps.** Selon la configuration de PHP, une requête plus grosse que `post_max_size` peut arriver vide. L'API répondait alors `400 JSON invalide`, un refus définitif. Elle le détecte maintenant (en-tête `Content-Length` annoncé, mais corps vide) et répond `413` avec la limite effective, la plus petite de `max_body_size` et `post_max_size` : le relais renvoie une version tronquée, et une ligne `[messages] corps vide alors que Content-Length annonce … octets` est écrite dans `var/log/api.log`. Avec PHP-FPM (cas des hébergements OVH), le corps est en général quand même lu (vérifié en PHP 8.3) : PHP écrit seulement un avertissement dans le log (voir [Dépannage](#dépannage)).

Fichiers sur le disque (dossier `webhook.storage_dir`, par défaut `var/messages/`) :

```
var/messages/<ID Mailpit>/part-<PartID>    une pièce jointe ou image intégrée (le nom d'origine est en base)
```

---

## Installation

### 1. Préparer les fichiers (sur votre ordinateur)

```bash
cd api
composer install --no-dev --optimize-autoloader
cp config.example.php config.php
```

`composer.json` fixe la plateforme sur PHP 8.5 : les dépendances installées sont celles qui conviennent à l'hébergement, même si votre PHP local est différent. `--no-dev` laisse de côté PHPUnit, qui ne sert qu'aux [tests automatisés](#tests-automatisés).

Éditez `config.php` :

| Clé | Valeur |
|---|---|
| `db.host`, `db.name`, `db.user`, `db.password` | Espace client OVH → **Hébergements** → votre hébergement → onglet **Bases de données** |
| `webhook.allowed_ips` | l'IPv4 du serveur Mailpit, et son IPv6 seulement si elle est stable (voir ci-dessous) |
| `webhook.token` | **obligatoire** : le secret partagé avec le relais, généré avec `openssl rand -hex 32` (32 caractères minimum). La même valeur va dans `RELAY_API_TOKEN` côté relais |
| `webhook.max_attachment_size` | taille maximale d'une pièce jointe, 5 Mo par défaut |
| `webhook.max_messages` | nombre de mails gardés, 1000 par défaut (comme `MP_MAX_MESSAGES` côté Mailpit). `0` = sans limite |
| `webhook.max_age_days` | les mails sont supprimés (base **et** pièces jointes) ce nombre de jours après leur réception par Mailpit, 7 par défaut (comme `MP_MAX_AGE=7d` côté Mailpit). `0` = jamais. Voir [Rétention](#rétention--7-jours-et-1000-mails-au-plus) |
| `app.session_lifetime` | Dashboard : déconnexion après ce nombre de secondes d'inactivité (2 h par défaut) |
| `app.timezone` | Dashboard : fuseau horaire d'affichage des dates (`Europe/Paris` par défaut) |
| `force_https` | `true` (défaut) : HTTPS obligatoire. `false` **uniquement** pour un essai en local sans HTTPS. Voir [HTTPS obligatoire](#https-obligatoire) |
| `log_file` | fichier de log de l'API, `var/log/api.log` par défaut. `null` = log par défaut de l'hébergement |
| `log_max_size` | taille, en octets, à laquelle le log est archivé en `api.log.1` : 10 Mo par défaut (`10 * 1024 * 1024`), 1 Mo minimum, `0` = jamais. Voir [Logs](#logs--rotation-à-10-mo) |
| `debug` | `false` en production. `true` affiche le détail des erreurs (exception, fichier, ligne) dans les réponses JSON et dans les pages d'erreur du Dashboard |

Générez le jeton une seule fois, puis copiez-le aux deux endroits :

```bash
openssl rand -hex 32
```

Pour trouver les IP du serveur Mailpit, lancez **sur ce serveur** :

```bash
curl -4 -s https://ifconfig.me; echo
```

```bash
curl -6 -s https://ifconfig.me; echo
```

> ⚠️ **IPv4 ou IPv6 ?** L'hébergement OVH répond aussi en IPv6. Le relais appelle l'API depuis son conteneur, et le réseau Podman n'utilise que l'**IPv4** : c'est donc l'IPv4 du serveur que l'API verra. Elle suffit dans `allowed_ips`.
>
> N'y mettez **pas** l'IPv6 affichée par `curl -6`. C'est souvent une **adresse temporaire**, que Linux renouvelle régulièrement (« privacy extensions ») : elle ne serait plus valable le lendemain. Si vous voulez autoriser l'IPv6, mettez la plage `/64` du réseau, par exemple `2001:db8:1:2::/64`.
>
> Conséquence pour les tests manuels depuis le serveur : forcez l'IPv4 avec **`curl -4`**, sinon `curl` passe par l'IPv6 et reçoit un `403`.

### 2. Créer les tables

Espace client OVH → **Bases de données** → **…** → **Aller sur phpMyAdmin**. Sélectionnez la base dans la colonne de gauche, puis onglet **Importer** → fichier `sql/schema.sql`. Il crée les tables `mailpit_messages`, `mailpit_parts` et `mailpit_message_recipients` (mails), `mailpit_admins` et `mailpit_login_attempts` (Dashboard), `mailpit_tokens` et `mailpit_token_addresses` (jetons développeurs), `mailpit_health_pings` (pings de santé du relais, page **Statut**).

> **Mise à jour d'une installation existante** : importez plutôt les fichiers de `sql/migrations/`, dans l'ordre, en sautant ceux déjà importés. Chacun peut être importé plusieurs fois sans risque.
>
> | Fichier | Pour une base créée… | Ce qu'il fait |
> |---------|----------------------|---------------|
> | `2026-11-dashboard-tokens.sql` | avant l'arrivée du Dashboard | Crée les nouvelles tables et y range les destinataires des mails déjà enregistrés |
> | `2026-11-login-throttle-ip.sql` | avant la limitation des connexions par adresse | Ajoute la colonne `ip_key` et ses index à `mailpit_login_attempts` |
> | `2026-11-health-pings.sql` | avant la page **Statut** | Crée la table `mailpit_health_pings` (pings de santé du relais) |
> | `2026-11-message-truncated.sql` | avant les mails tronqués | Ajoute la colonne `truncated` à `mailpit_messages` (`0` pour les mails déjà enregistrés) |
>
> ⚠️ La suppression des mails au bout de 7 jours ne demande aucune migration, mais elle s'applique **dès la mise à jour** : sans clé `max_age_days` dans votre `config.php`, les mails reçus il y a plus de 7 jours sont supprimés (base et pièces jointes) après la première requête du relais. Pour les garder, ajoutez `'max_age_days' => 0` (ou une autre durée) dans la section `webhook` **avant** d'envoyer les nouveaux fichiers. Voir [Rétention](#rétention--7-jours-et-1000-mails-au-plus).

### 2 bis. Créer un administrateur du Dashboard

Sur votre ordinateur, dans le dossier `api/` :

```bash
php bin/create-admin.php
```

Le script demande un identifiant et un mot de passe (12 caractères minimum), puis affiche une requête SQL `INSERT INTO mailpit_admins …`, avec le mot de passe **haché**. Copiez-la dans phpMyAdmin, onglet **SQL**, puis **Exécuter**. Recommencez pour chaque administrateur. Relancé avec un identifiant existant, le script produit une requête qui **change son mot de passe**.

Pour supprimer un administrateur : supprimez sa ligne dans `mailpit_admins`. Il est déconnecté à sa requête suivante.

### 3. Envoyer en FTP

Créez par exemple un dossier `api` à la racine FTP de l'hébergement (à côté de `www/`), puis envoyez-y **tout le contenu** de `api/`, **y compris** `vendor/`, `config.php`, `.htaccess`, `public/.htaccess`, `var/.htaccess` et `.ovhconfig`.

> 💡 Les fichiers qui commencent par un point sont souvent masqués dans les clients FTP. Dans FileZilla : menu **Serveur** → **Forcer l'affichage des fichiers cachés**.

Envoyez aussi `templates/` (pages et script du Dashboard). Inutile d'envoyer `README.md`, `bin/`, `sql/`, `tests/`, `phpunit.xml.dist` et `config.example.php` (ils sont de toute façon bloqués par le `.htaccess`). Seule exception, facultative : `bin/cleanup.php`, si vous voulez lancer le nettoyage depuis une tâche planifiée (voir [Rétention](#rétention--7-jours-et-1000-mails-au-plus)).

Les dossiers `var/log/` et `var/messages/` doivent être accessibles en écriture par PHP (droits `755` ou `705`, ce qui est le cas par défaut chez OVH). `var/log/` reste petit : le log est archivé à 10 Mo, et une seule archive est gardée ([Logs](#logs--rotation-à-10-mo)). `var/messages/` contiendra toutes les pièces jointes des 7 derniers jours (1000 mails au plus) : surveillez l'espace disque de l'hébergement.

### 4. Brancher le domaine `api.amnesic.dev`

Espace client OVH → **Hébergements** → onglet **Multisite** → **Ajouter un domaine ou sous-domaine** → `api.amnesic.dev`, puis :

- **Dossier racine** : `api/public` (recommandé : seul `public/` est alors visible depuis le web).
  `api` fonctionne aussi : le `.htaccess` racine redirige vers `public/` et bloque le reste.
- Cochez **SSL** et attendez que le certificat soit généré. Le relais appellera l'API en HTTPS.

> ⚠️ L'API **impose HTTPS** : toute page demandée en `http://` est redirigée vers `https://`. Activez donc le certificat SSL **avant** les premiers essais : sans lui, le navigateur et `curl` affichent une erreur de certificat après la redirection. Vérifiez dans l'onglet **Multisite** que la colonne **SSL** indique **Activé** pour `api.amnesic.dev` (sinon : **…** → **Modifier le domaine** → cochez **SSL**, puis onglet **Informations générales** → **Certificat SSL** → **Regénérer le certificat SSL**). Comptez jusqu'à quelques heures.

**Version de PHP** : le fichier `.ovhconfig` demande PHP 8.5. OVH le lit à la racine de l'hébergement ou à la racine du dossier du multisite. Si vous choisissez `api/public` comme dossier racine, **déplacez `.ovhconfig` dans `public/`**. Si OVH ne propose pas encore la 8.5, mettez `8.4` (le minimum de l'API). Vérifiez la version active dans l'onglet **Informations générales** de l'hébergement.

### 5. Configurer Mailpit

Installez le relais sur le serveur Mailpit : suivez la [Partie 7 du tuto](../docs/mailpit/07-relay.md). `https://api.amnesic.dev` va dans `RELAY_API_URL`, le jeton dans `RELAY_API_TOKEN`, et Mailpit appelle le relais. Le relais envoie le mail complet (pièces jointes comprises) et réessaie tant que l'API ne l'a pas entièrement enregistré.

> ⚠️ Mailpit ne doit **pas** appeler l'API directement : il ne sait pas ajouter d'en-tête, et l'API refuse un jeton passé dans l'URL (`401`). Une ancienne configuration avec `MP_WEBHOOK_URL=https://api.amnesic.dev/v1/messages?token=…` dans `/etc/mailpit/mailpit.env` ne fonctionne donc plus : remplacez-la par l'URL du relais ([Partie 7, étape 7](../docs/mailpit/07-relay.md#étape-7--envoyer-le-webhook-de-mailpit-au-relais)), puis **changez le jeton** (voir [Changer le jeton](#dépannage)) : il figure dans les logs d'accès de l'hébergement.

---

## Tester

**Depuis le serveur Mailpit** (le seul autorisé), un appel manuel. Pour une recette complète, pas à pas, voir la [Partie 8 du tutoriel](../docs/mailpit/08-tests-relay-api.md) :

```bash
curl -4 -i -X POST 'https://api.amnesic.dev/v1/messages' \
  -H 'Authorization: Bearer LE_JETON_DE_CONFIG_PHP' \
  -H 'Content-Type: application/json' \
  -d '{"ID":"test-manuel-1","Created":"'"$(date -u +%FT%TZ)"'","Subject":"Test manuel","From":{"Name":"","Address":"moi@exemple.fr"},"To":[],"Parts":[{"PartID":"2","Kind":"attachment","FileName":"test.txt","ContentType":"text/plain","ContentID":"","Size":5}]}'
```

Réponse attendue : `HTTP/2 201` puis `{"id":"test-manuel-1","status":"created","complete":false,"missing":["2"],"too_large":[]}`. La date de réception (`Created`) est l'heure actuelle (`date -u`) : un mail reçu par Mailpit il y a plus de 7 jours serait supprimé par le [nettoyage](#rétention--7-jours-et-1000-mails-au-plus) dès cette requête. Envoyez ensuite la pièce annoncée :

```bash
printf 'hello' > /tmp/test.txt
```

```bash
curl -4 -i -X POST 'https://api.amnesic.dev/v1/messages/test-manuel-1/part/2' \
  -H 'Authorization: Bearer LE_JETON_DE_CONFIG_PHP' \
  -H 'Content-Type: application/octet-stream' \
  -H "X-Content-SHA256: $(sha256sum /tmp/test.txt | cut -d' ' -f1)" \
  --data-binary @/tmp/test.txt
```

Réponse attendue : `HTTP/2 201` et `"complete":true`. Le fichier est dans `var/messages/test-manuel-1/part-2`.

Puis la liste (résumés, sans le corps), et le mail complet (corps, en-têtes) :

```bash
curl -4 -s 'https://api.amnesic.dev/v1/messages' -H 'Authorization: Bearer LE_JETON_DE_CONFIG_PHP'
curl -4 -s 'https://api.amnesic.dev/v1/messages/test-manuel-1' -H 'Authorization: Bearer LE_JETON_DE_CONFIG_PHP'
```

Supprimez ensuite la ligne de test dans phpMyAdmin (sa pièce est supprimée avec elle) et le dossier `var/messages/test-manuel-1/` par FTP.

**Pièce jointe de 5 Mo** : l'hébergement doit accepter des requêtes `POST` de cette taille (réglage PHP `post_max_size`, plus élevé que 5 Mo sur les hébergements OVH). Pour vérifier, recommencez le test ci-dessus avec un fichier de 5 Mo (`head -c 5242880 /dev/urandom > /tmp/test.txt`) et `"Size":5242880` dans le `POST`.

**De bout en bout** : envoyez un mail comme en [Partie 1, étape 5](../docs/mailpit/01-podman.md#étape-5--démarrer-le-service) :

```bash
swaks --server 127.0.0.1 --port 25 --to essai@test.mondomaine.fr
```

Le message doit apparaître dans la table `mailpit_messages`.

**Depuis votre ordinateur**, le `POST` ci-dessus doit renvoyer `403` : le filtre IP fonctionne. Depuis le serveur Mailpit, sans l'en-tête `Authorization` ou avec un mauvais jeton, il doit renvoyer `401`.

**Dashboard** : ouvrez `https://api.amnesic.dev/v1/app/signin` et connectez-vous avec le compte créé à l'étape 2 bis. Créez un jeton dans **Tokens**, associez-lui `essai@test.mondomaine.fr`, puis, depuis n'importe quelle machine :

```bash
curl -s 'https://api.amnesic.dev/v1/messages?address=essai@test.mondomaine.fr' -H 'Authorization: Bearer mpt_LE_JETON_AFFICHÉ'
```

**Ping de santé** : le relais en envoie un toutes les 30 secondes. Pour en envoyer un à la main, depuis le serveur Mailpit :

```bash
curl -4 -s -X POST 'https://api.amnesic.dev/v1/health' \
  -H 'Authorization: Bearer LE_JETON_DE_CONFIG_PHP' \
  -H 'Content-Type: application/json' \
  -d '{"latency_ms":null,"pending":0,"failed":0}'
```

Réponse attendue : `{"status":"ok","time":"…"}`. Dans le Dashboard, la page **Statut** indique **Connecté** (voir [Page Statut](#page-statut)).

**Fichiers protégés** : `https://api.amnesic.dev/config.php`, `/vendor/autoload.php`, `/.ovhconfig`… doivent tous renvoyer `403` ou `404`.

**HTTPS obligatoire** :

```bash
# Page en http:// : 301 vers https://
curl -4 -s -o /dev/null -w '%{http_code} %{redirect_url}\n' 'http://api.amnesic.dev/v1/app/signin'
# Requête POST en http:// : 403
curl -4 -s -w '\n%{http_code}\n' -X POST 'http://api.amnesic.dev/v1/messages'
# En HTTPS : en-tête Strict-Transport-Security
curl -4 -s -D - -o /dev/null 'https://api.amnesic.dev/v1/app/signin' | grep -i strict-transport
```

Attendu : `301 https://api.amnesic.dev/v1/app/signin`, puis `{"error":"HTTPS obligatoire : renvoyez la requête en https://"}` et `403`, puis `Strict-Transport-Security: max-age=31536000`.

---

## Tests automatisés

Les tests **PHPUnit** vérifient le code sur votre ordinateur, sans rien déployer. Ils ne remplacent pas les essais ci-dessus, qui vérifient l'installation réelle (hébergement, DNS, `.htaccess`…).

| Suite | Dossier | Ce qui est testé | Base MySQL |
|---|---|---|---|
| `unit` | `tests/Unit/` | classes sans base : filtre IP (`IpMatcher`, `ClientIpResolver`), adresses, validation du JSON des mails et des pièces (chaque erreur `422`, champ `Truncated`), configuration (`Settings`), HTTPS (`HttpsDetector` : `HTTPS`, port 443, `X-Forwarded-Proto` d'un proxy de confiance ; `ForceHttpsMiddleware` : `301`, `403`, HSTS), filtres du Dashboard, pages d'erreur du Dashboard (`ErrorPage` : URL concernées, titres et messages selon le code, page minimale si le gabarit ne peut pas être rendu), jetons (`ApiScope`, `Authorization: Bearer`, jeton du relais refusé dans l'URL et avertissement sans sa valeur), page Statut (`HealthReport` : état connecté / déconnecté, créneaux de 5 minutes, disponibilité depuis le premier ping, coupures de plus de 90 s ; `LatencyChart` : graphique SVG, axes à l'heure du Dashboard, résumé accessible), adresse comptée par la limite de connexion (`LoginThrottle::ipKey`), rotation du log (`LogRotator` : seuil, archive remplacée, fichier absent, `log_file` à `null`, verrou pris, échec sans exception, requêtes simultanées dans plusieurs processus) | non |
| `integration` | `tests/Integration/` | accès à la base : mails, pièces, version tronquée puis complète (et l'inverse), purge, suppression par âge (`created_at`), pièce d'un mail supprimé, nettoyage (`Maintenance` : mails trop anciens ou en trop, fichiers temporaires vieux ou récents, dossiers et fichiers sans ligne en base, au plus toutes les 15 minutes, verrou, erreur seulement écrite dans le log), liste par adresse (sans corps), mail complet limité aux adresses d'un jeton, suppression, jetons développeurs, limite de connexion au Dashboard (par identifiant et adresse, plafond par adresse, IPv6 par `/64`), pings de santé (`HealthRepository` : heures en UTC, ping précédent, purge) | oui |
| `http` | `tests/Http/` | l'application complète (`Kernel::create`) avec de fausses requêtes : routes du relais (`403`, `401`, `401` avec `?token=…`, `415`, `400`, `413` avec `max_body_size`, corps vidé par PHP (`413` avec la limite effective), `422`, `201`, `200`, mail tronqué enregistré et exposé : `"truncated": true`), ping de santé (`POST /v1/health` : `403`, `401`, `415`, `413`, `400`, `422`, `200` et ligne enregistrée, pings de plus de 48 h supprimés, nettoyage lancé sans jamais faire échouer le ping), envoi des pièces jusqu'à `"complete": true`, routes des développeurs (liste sans corps, mail complet par son ID, `404` hors des adresses du jeton, jeton du relais selon l'IP), Dashboard (redirection vers la connexion, refus sans jeton anti-CSRF, messages de blocage, badge « tronqué », cookie de session `Secure`, exemple `curl` de la page du jeton en `https://` derrière le frontal OVH, pages d'erreur HTML `404` / `405` / `500` avec les en-têtes de sécurité, menu seulement si connecté, base injoignable, détail seulement avec `debug`, page **Statut** : « Aucun ping reçu », puis « Connecté », compteurs, graphique, coupure, rechargement automatique qui ne prolonge pas la session), erreurs de l'API toujours en JSON (`404`, `405`, `500`, `base_path`), requêtes en clair (`301`, `403` sans rien enregistrer, proxy de confiance, `force_https` désactivé), nettoyage après les requêtes acceptées du relais (et pas après les autres), pièce d'un mail supprimé pendant l'envoi (`404`, fichier effacé), rotation du log au démarrage d'une requête (et requête servie quand l'archivage échoue) | oui |

**Prérequis** : PHP 8.4 minimum avec `pdo_mysql` et `mbstring`, Composer, et une base MySQL **de test** (8.4 comme l'hébergement ; 8.0.19 minimum).

> ⚠️ Les tests **suppriment toutes les tables** de la base de test, puis importent `sql/schema.sql` : n'utilisez jamais une vraie base. Par sécurité, son nom doit contenir `test`.

Créez la base une fois (en `root`) :

```sql
CREATE DATABASE mailpit_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'mailpit'@'localhost' IDENTIFIED BY 'mailpit';
GRANT ALL PRIVILEGES ON mailpit_test.* TO 'mailpit'@'localhost';
```

Puis, dans le dossier `api/` :

```bash
composer install
vendor/bin/phpunit
```

> ⚠️ Sans `--no-dev`, `composer install` ajoute PHPUnit dans `vendor/`. Avant un envoi FTP, relancez `composer install --no-dev --optimize-autoloader` (voir [Préparer les fichiers](#1-préparer-les-fichiers-sur-votre-ordinateur)).

Pour une seule suite : `vendor/bin/phpunit --testsuite unit` (aucune base nécessaire). Pour un seul fichier : `vendor/bin/phpunit tests/Http/RelayRoutesTest.php`.

La base est lue dans ces variables d'environnement (valeurs par défaut dans `phpunit.xml.dist`) :

| Variable | Défaut |
|---|---|
| `TEST_DB_HOST` | `127.0.0.1` |
| `TEST_DB_PORT` | `3306` |
| `TEST_DB_NAME` | `mailpit_test` |
| `TEST_DB_USER` | `mailpit` |
| `TEST_DB_PASSWORD` | `mailpit` |

```bash
TEST_DB_HOST=192.168.1.20 TEST_DB_PASSWORD=autre vendor/bin/phpunit
```

> 💡 Base injoignable : les tests `integration` et `http` sont marqués « skipped » (S), les tests `unit` passent quand même.

Les pièces jointes des tests sont écrites dans un dossier temporaire, supprimé après chaque test. Les messages que le code écrit dans le log (`error_log`) vont dans `mailpit-api-tests.log`, dans le dossier temporaire du système (`/tmp` en général).

**CI** : à chaque `push` et `pull request`, GitHub Actions lance ces tests en PHP 8.5 avec un service MySQL 8.4 (`.github/workflows/tests.yml`, job `api`), ainsi que ceux du [relais](../relay/README.md#tests). En CI, un test « skipped » fait échouer le job (`--fail-on-skipped`).

---

## Dépannage

| Symptôme | Cause probable / solution |
|---|---|
| `403` depuis le serveur Mailpit | L'IP vue par l'API n'est pas dans `allowed_ips`. Elle est écrite dans `var/log/api.log` (`[ip-filter] requête refusée depuis « … »`) : ajoutez-la. C'est souvent l'IPv6 : un `curl` lancé sans `-4` |
| `401` | Jeton absent ou différent de `webhook.token` (`[auth] jeton absent` ou `[auth] jeton invalide` dans `var/log/api.log`). Vérifiez `RELAY_API_TOKEN` (relais) |
| `401` avec `[auth] jeton dans l'URL (?token=…), ignoré` dans le log | Le jeton a été passé dans l'URL : il n'est accepté que dans l'en-tête `Authorization`. Souvent une ancienne configuration de Mailpit (`MP_WEBHOOK_URL=…/v1/messages?token=…`) : envoyez le webhook au relais ([étape 5](#5-configurer-mailpit)). Si le log dit `jeton du relais dans l'URL`, c'était le vrai jeton : changez-le |
| `401` avec `jeton absent` alors que le relais envoie bien le jeton | L'en-tête `Authorization` n'arrive pas jusqu'à PHP : vérifiez que les `.htaccess` (racine et `public/`) ont bien été envoyés, à jour |
| `403` avec `{"error":"HTTPS obligatoire : renvoyez la requête en https://"}` | La requête est partie en `http://` (`[https] requête en clair refusée …` dans `var/log/api.log`) : mettez `https://` dans l'URL (`RELAY_API_URL` pour le relais). Si le log ajoute `en-tête Authorization envoyé en clair`, le jeton a circulé sans chiffrement : changez-le |
| Erreur de certificat après la redirection vers `https://` | Certificat SSL pas encore généré ou pas activé pour ce domaine : voir l'[étape 4](#4-brancher-le-domaine-apiamnesicdev) |
| « Trop de redirections » (`ERR_TOO_MANY_REDIRECTS`) | Un proxy ou un CDN joint l'hébergement en `http://` sans être listé dans `proxy.trusted_proxies` : voir [Derrière un proxy ou un CDN](#derrière-un-proxy-ou-un-cdn) |
| `500` avec `{"error":"Erreur de configuration du serveur"}` | `vendor/` ou `config.php` absent, ou `config.php` invalide (détail dans `var/log/api.log`). Souvent : `webhook.token` vide ou trop court (**obligatoire**, 32 caractères minimum) |
| `500` avec `Slim Application Error` | Souvent la base : identifiants faux ou table absente. Détail dans `var/log/api.log` |
| `[log] archivage de « …/api.log » en « …/api.log.1 » impossible` dans le log | PHP ne peut pas renommer le log : rendez `var/log/` accessible en écriture (droits `755` ou `705`). Les requêtes sont servies normalement, mais le log grossit tant que ce n'est pas corrigé |
| `404` sur `/v1/messages` | `.htaccess` non envoyés (fichiers cachés), ou mauvais dossier racine du multisite. Si l'API est dans un sous-dossier de l'URL, renseignez `base_path` |
| Certains mails manquent en base | Le relais ne les a pas encore livrés (API en panne, jeton ou IP refusés : il réessaie), ou les a refusés définitivement (tag `relay-failed` dans Mailpit). Voir ses logs : `sudo journalctl -u mailpit-relay -n 50` |
| `413` sur `POST /v1/messages` | Le JSON du mail (texte + HTML + en-têtes) dépasse `webhook.max_body_size` (16 Mo par défaut). Normal : le relais renvoie une version tronquée (badge **tronqué** dans le Dashboard). Pour garder ces mails entiers, augmentez la limite ([Mails trop volumineux](#mails-trop-volumineux--tronqués-par-le-relais)) |
| Mail avec le badge **tronqué** (`"truncated": true`) | Trop volumineux pour `webhook.max_body_size` : texte, HTML ou en-têtes raccourcis par le relais. Le mail complet est encore dans Mailpit. Pour le recevoir en entier : augmentez `max_body_size`, puis retirez le tag `relayed` du mail dans Mailpit (le relais le renvoie, la version complète remplace la version tronquée) |
| Relais : `refusé par l'API (API (message tronqué) refus définitif : HTTP 413 …)` | Même tronqué 3 fois, le mail ne passe pas : un proxy devant l'API limite les requêtes plus bas que `max_body_size`, ou le mail a d'énormes listes de destinataires. Voir les logs du relais |
| `[messages] corps vide alors que Content-Length annonce … octets` dans le log | PHP a vidé un corps plus gros que `post_max_size` : l'API a répondu `413` avec la limite effective et le relais a renvoyé une version tronquée. Pour que le premier envoi tronqué passe, mettez `webhook.max_body_size` sous `post_max_size` |
| `413` sur `POST /v1/messages/…/part/…` | La pièce dépasse la limite, alors que sa taille annoncée était plus petite : cas anormal, voir les logs du relais |
| Log PHP `POST Content-Length of … exceeds the limit` | Un mail au texte ou HTML très lourd, ou une pièce jointe, dépasse `post_max_size` de l'hébergement. Le JSON est quand même lu et enregistré (PHP-FPM, vérifié en PHP 8.3) : l'avertissement est sans effet tant que `display_errors` est désactivé (`environment=production` dans `.ovhconfig`). Si votre PHP vide ces corps, l'API répond `413` (ligne `[messages] corps vide …` plus haut) |
| Pièce jointe en `too_large` | Elle dépasse `webhook.max_attachment_size` (5 Mo par défaut). Seule sa description est enregistrée |
| Des mails anciens ont disparu | Normal : les mails sont supprimés 7 jours après leur réception par Mailpit (`webhook.max_age_days`), et seuls les `webhook.max_messages` derniers (1000 par défaut) sont gardés. Chaque nettoyage est résumé dans `var/log/api.log` (`[maintenance] …`), les échecs de purge aussi (`[prune] …`) |
| Des mails de plus de 7 jours sont encore là | Le nettoyage n'a lieu qu'après une requête du relais (mail, pièce jointe ou ping de santé, toutes les 30 s) : relais arrêté, ou ping de santé désactivé et aucun mail reçu depuis. Lancez-le à la main (`php bin/cleanup.php`), ou planifiez-le ([Rétention](#rétention--7-jours-et-1000-mails-au-plus)) |
| `[maintenance] nettoyage impossible : …` dans le log | Base injoignable, ou `var/messages/` (`webhook.storage_dir`) pas accessible en écriture. Les mails du relais sont quand même enregistrés ; le nettoyage est retenté 15 minutes plus tard |
| `text_body` vide | Mail reçu par l'ancien webhook direct de Mailpit (résumé seulement), avant le passage au relais, ou `sql/schema.sql` n'a pas été importé (erreur `Table … doesn't exist` dans le log) |
| Mail avec `completed_at` vide | Des pièces sont encore attendues (`status = 'pending'` dans `mailpit_parts`). Le relais les renvoie tout seul : voir ses logs |
| Page blanche / erreur PHP | Version de PHP trop ancienne : vérifiez `.ovhconfig` (8.4 minimum) |
| Dashboard : « Trop de tentatives pour cet identifiant depuis votre adresse » | 3 échecs en moins de 30 minutes pour cet identifiant depuis votre adresse : attendez la fin du délai affiché, ou connectez-vous depuis une autre adresse. Pour débloquer tout de suite : `DELETE FROM mailpit_login_attempts WHERE username = 'identifiant';` dans phpMyAdmin. Les échecs sont dans `var/log/api.log` (`[signin] …`) |
| Dashboard : « Trop de tentatives depuis votre adresse » | 10 échecs en moins de 30 minutes depuis votre adresse, tous identifiants confondus (IPv6 : tout le `/64`). Pour débloquer tout de suite : `DELETE FROM mailpit_login_attempts WHERE ip_key = 'adresse';` (adresse IPv4, ou préfixe comme `2001:db8:1:2::/64`, écrit entre parenthèses après l'adresse IPv6 dans le log `[signin] …`) |
| Dashboard : « Formulaire expiré : recommencez » | La page est restée ouverte trop longtemps (session expirée), ou les cookies sont bloqués pour le domaine |
| Dashboard : page « Erreur interne » (`500`) | Le détail est dans `var/log/api.log`. Souvent la base : identifiants de `config.php`, base arrêtée, migration manquante (lignes suivantes) |
| Dashboard : `{"message":"404 Not Found"}` (JSON) au lieu d'une page d'erreur | L'API en ligne est une version précédente : renvoyez `src/` et `templates/` en FTP (`templates/error.php` est nouveau) |
| Dashboard : erreur `500` avec `Table … doesn't exist` dans le log | La migration `sql/migrations/2026-11-dashboard-tokens.sql` n'a pas été importée |
| Dashboard : erreur `500` à la connexion, avec `Field 'ip_key' doesn't have a default value` dans le log | La migration `2026-11-login-throttle-ip.sql` a été importée, mais les fichiers de l'API n'ont pas encore été mis à jour : envoyez la nouvelle version en FTP (au moins `src/` et `templates/`) |
| Dashboard : erreur `500` à la connexion, avec `Unknown column 'ip_key'` dans le log | La migration `sql/migrations/2026-11-login-throttle-ip.sql` n'a pas été importée |
| Page **Statut** : erreur `500` avec `Table '…mailpit_health_pings' doesn't exist` dans le log | La migration `sql/migrations/2026-11-health-pings.sql` n'a pas été importée. Le relais reçoit aussi des `500` sur ses pings (ses mails, eux, sont bien enregistrés) |
| Dashboard : erreur `500` avec `Unknown column 'truncated'` dans le log (le relais reçoit des `500` sur ses mails) | La migration `sql/migrations/2026-11-message-truncated.sql` n'a pas été importée. Les mails attendent dans Mailpit et seront relayés dès qu'elle l'est |
| Page **Statut** : « Aucun ping reçu » | Le relais n'envoie pas de ping : version précédente (sans `bin/heartbeat.php` : reconstruisez l'image, [Partie 7](../docs/mailpit/07-relay.md)), `RELAY_HEARTBEAT_INTERVAL=0`, ou pings refusés (`401`, `403` : voir `sudo journalctl -u mailpit-relay -n 50`, lignes `ping de santé …`) |
| Page **Statut** : « Déconnecté depuis … » | Aucun ping depuis plus de 90 secondes : relais arrêté (`sudo systemctl status mailpit-relay`), serveur Mailpit éteint, réseau coupé, ou API en panne (`500` : voir `var/log/api.log`). Les mails attendent dans Mailpit et seront relayés au retour |
| Relais : `ping de santé non enregistré : API : HTTP 404` dans son journal | L'API en ligne est une version précédente, sans `POST /v1/health` : renvoyez `src/` et `templates/` en FTP et importez la migration `2026-11-health-pings.sql`. Les mails sont relayés normalement en attendant |
| Jeton développeur : `401` | Jeton faux, supprimé, ou envoyé ailleurs que dans l'en-tête `Authorization: Bearer …` (`[api-auth] …` dans `var/log/api.log`) |
| Jeton développeur : `"total": 0` alors que des mails existent | Aucune adresse associée au jeton, ou l'adresse ne correspond à aucun destinataire `To` / `Cc` / `Bcc` |
| Jeton développeur : `403` | `?address=` désigne une adresse qui n'est pas associée au jeton |
| Jeton développeur : `404` sur `/v1/messages/{id}` | Mail reçu par aucune des adresses du jeton, déjà supprimé (rétention, `DELETE`), ou ID mal copié : c'est le champ `id` de la liste, pas `message_id` |
| `405` sur `GET /v1/messages/{id}` | L'API en ligne est une version précédente, sans cette route (seul `DELETE` existe à cette adresse) : renvoyez `src/` en FTP |
| `GET /v1/messages` : plus de `text` ni de `html` | Normal : la liste ne donne que des résumés. Le corps et les en-têtes se lisent avec `GET /v1/messages/{id}` ([Lire un mail](#lire-un-mail--get-v1messagesid)) |

Le fichier `var/log/api.log` n'a pas besoin d'être vidé : il est archivé tout seul à 10 Mo (voir [Logs](#logs--rotation-à-10-mo)).

> ℹ️ Le jeton voyage uniquement dans un en-tête : il n'apparaît pas dans les logs d'accès de l'hébergement. Un jeton passé dans l'URL (`?token=…`) est refusé, mais il figure quand même dans ces logs (visibles depuis votre espace client OVH). Si `var/log/api.log` signale `[auth] jeton du relais dans l'URL`, changez le jeton.

**Changer le jeton** : générez-en un nouveau (`openssl rand -hex 32`), mettez-le dans `config.php` (envoi FTP) **et** dans `RELAY_API_TOKEN` (`/etc/mailpit/relay.env`, puis `sudo systemctl restart mailpit-relay`). Entre les deux, le relais reçoit des `401` et réessaie : aucun mail n'est perdu.

## Requêtes utiles

```sql
-- Derniers mails reçus
SELECT created_at, from_address, subject FROM mailpit_messages ORDER BY created_at DESC LIMIT 20;

-- Mails reçus par une adresse donnée (To, Cc ou Bcc)
SELECT m.created_at, m.subject FROM mailpit_messages m
JOIN mailpit_message_recipients r ON r.message_id = m.id
WHERE r.address = 'essai@test.mondomaine.fr'
ORDER BY m.created_at DESC;

-- Jetons développeurs et leurs adresses
SELECT t.id, t.name, t.last_used_at, a.address FROM mailpit_tokens t
LEFT JOIN mailpit_token_addresses a ON a.token_id = t.id ORDER BY t.id, a.address;

-- Pièces jointes d'un mail, avec le fichier correspondant dans var/messages/
SELECT p.file_name, p.content_type, p.size, p.status, p.storage_path
FROM mailpit_parts p JOIN mailpit_messages m ON m.id = p.message_id
WHERE m.mailpit_id = 'Wdyx4jVvBWELmoNePrh8Ff';

-- Mails incomplets (pièces encore attendues)
SELECT mailpit_id, received_at, subject FROM mailpit_messages WHERE completed_at IS NULL;

-- Valeur d'un en-tête
SELECT subject, headers->>'$."X-Mailer"[0]' AS mailer FROM mailpit_messages ORDER BY id DESC LIMIT 10;

-- Derniers pings de santé du relais (heures en UTC)
SELECT received_at, latency_ms, pending, failed, source_ip, label FROM mailpit_health_pings ORDER BY id DESC LIMIT 20;
```

## Dashboard

`https://api.amnesic.dev/v1/app/signin`, avec un compte de la table `mailpit_admins` ([étape 2 bis](#2-bis-créer-un-administrateur-du-dashboard)).

- **Messages** : tous les mails enregistrés, 50 par page : date de réception, expéditeur, destinataires (`To`, `Cc`, `Bcc`), sujet et début du texte, nombre de pièces jointes. Un mail dont des pièces sont encore attendues est marqué « incomplet », un mail raccourci par le relais parce qu'il était trop volumineux est marqué « tronqué » ([Mails trop volumineux](#mails-trop-volumineux--tronqués-par-le-relais)). Deux filtres :
  - **Adresses** : liste déroulante de toutes les adresses ayant reçu un mail (`To`, `Cc` ou `Bcc`), avec leur nombre de mails. Cochez-en une ou plusieurs puis **Filtrer** : seuls les mails reçus par l'une d'elles restent ;
  - **Ordre** : du plus récent au plus ancien (par défaut) ou l'inverse. Un clic sur l'en-tête de colonne « Reçu le » inverse aussi l'ordre.

  Les filtres sont dans l'URL : la pagination et le retour depuis un mail les conservent, et une liste filtrée peut être mise en favori.
  **Suppression** : cochez des mails (la case de l'en-tête coche toute la page), puis **Supprimer la sélection (N)**. Le navigateur demande confirmation. On revient ensuite sur la même liste, mêmes filtres.
- **Un mail** (clic sur sa date ou son sujet), en trois onglets :
  - **Mail** : expéditeur, destinataires (`To`, `Cc`, `Bcc`), `Répondre à`, dates (en-tête `Date`, réception par Mailpit et par l'API), taille, tags, identifiants, puis le contenu en **version texte**. Le bouton **HTML** affiche la version HTML à la place (seule version affichée si le mail n'a pas de texte) ;
  - **Pièces jointes (N)** : pièces jointes et images intégrées, avec un lien **Télécharger** (nom de fichier d'origine). Une pièce trop volumineuse (`too_large`) ou pas encore reçue est listée, sans lien ;
  - **En-têtes (N)** : tous les en-têtes du mail, dans l'ordre.

  Chaque onglet a sa propre URL. Le bouton **Supprimer ce mail**, au-dessus des onglets, le supprime après confirmation et ramène à la liste. Un mail tronqué porte le badge « tronqué » au-dessus des onglets, avec une phrase qui l'explique ; la note `[… contenu tronqué par le relais …]` termine son texte et son HTML.

Une suppression efface le mail de la base (pièces jointes et destinataires compris) et ses fichiers dans `var/messages/`, comme `DELETE /v1/messages/{id}` côté jetons. Le mail reste dans **Mailpit**. Si le relais l'y renvoyait (mail pas encore complet au moment de la suppression), il réapparaîtrait.
- **Tokens** : création (un nom suffit) et suppression des jetons développeurs. Un jeton supprimé est refusé (`401`) dès l'appel suivant.
- **Détail d'un jeton** : associer ou retirer des adresses, et voir combien de mails chacune a reçus.
- **Statut** : connexion du relais sur les dernières 24 heures, d'après ses pings de santé : voir [Page Statut](#page-statut).

**Pages d'erreur.** Une adresse inconnue, un mail ou un jeton qui n'existe plus, une erreur du serveur : le Dashboard affiche une page d'erreur, dans sa mise en page, avec le bon code HTTP et un lien **Retour aux mails** (jamais de JSON brut). Le menu n'y apparaît que si vous êtes connecté. Une erreur `500` (base injoignable, par ex.) dit seulement « Erreur interne » : le détail est dans `var/log/api.log`, ou sur la page avec `'debug' => true`. Les `404` et `405` ne sont pas écrites dans le log. Si la page elle-même ne peut pas être affichée, une page HTML minimale la remplace (et `[dashboard] page d'erreur … impossible à afficher` est écrit dans le log).

**Affichage du HTML d'un mail.** Le HTML vient de l'expéditeur : il est affiché dans un cadre isolé (`iframe` + CSP `sandbox`), où aucun script ni formulaire ne fonctionne, et sans accès à la session du Dashboard. Les images intégrées (`cid:…`) y sont incluses directement. Les **images distantes sont bloquées** par défaut : elles diraient à l'expéditeur que le mail a été ouvert (pixel de suivi). Le lien **Afficher les images distantes** les autorise pour ce mail. Les liens s'ouvrent dans un nouvel onglet.

> ⚠️ **Le jeton n'est affiché qu'une fois**, juste après sa création. Seule son empreinte SHA-256 est gardée en base : s'il est perdu, supprimez-le et créez-en un autre.

**Sécurité.** Les pages n'exécutent qu'un seul script, `dashboard.js`, servi par l'API (CSP `script-src 'self'`) : il affiche les confirmations et gère les cases à cocher. Connexion limitée, sur 30 minutes, à **3 échecs par identifiant depuis une même adresse** et à **10 échecs par adresse**, tous identifiants confondus. Au-delà, la connexion est refusée (`429`, en-tête `Retry-After`) jusqu'à 30 minutes après le plus ancien des échecs comptés, même avec le bon mot de passe. Le blocage d'un identifiant ne vaut que pour l'adresse qui a échoué : quelqu'un d'autre ne peut pas bloquer votre compte. Une IPv4 est comptée à l'adresse exacte, une IPv6 par préfixe `/64` (sinon il suffirait de changer d'adresse dans son `/64`). Une connexion réussie remet à zéro le compteur de cet identifiant depuis cette adresse. Un identifiant inexistant est traité de la même façon, pour ne pas révéler quels comptes existent. Derrière un proxy, l'adresse est celle du client, déterminée comme pour le filtre IP (`proxy.trusted_proxies`). Les autres protections : mots de passe hachés (`password_hash`), HTTPS obligatoire ([voir plus bas](#https-obligatoire)), cookie de session `HttpOnly`, `SameSite=Strict` et `Secure`, limité au chemin `/v1/app`, jeton anti-CSRF dans chaque formulaire, déconnexion après 2 h d'inactivité (`app.session_lifetime`), pages (pages d'erreur comprises) jamais mises en cache ni affichables dans une iframe.

Le Dashboard est accessible depuis **n'importe quelle IP** : `webhook.allowed_ips` ne concerne que le relais.

## Page Statut

`https://api.amnesic.dev/v1/app/status` (menu **Statut** du Dashboard) : la connexion entre le [relais](../relay/README.md#ping-de-santé) et l'API sur les **dernières 24 heures**.

Le relais envoie un **ping de santé** (`POST /v1/health`) toutes les 30 secondes, depuis un processus à part : un gros mail en cours d'envoi ne le retarde pas. Chaque ping donne la latence du ping précédent (aller-retour HTTP mesuré par le relais) et le nombre de mails en attente et refusés dans Mailpit. L'API l'enregistre dans `mailpit_health_pings`, et supprime à cette occasion les pings de plus de **48 heures** : la table garde au plus 5 760 lignes.

| Bloc | Contenu |
|---|---|
| **État** | **Connecté** si le dernier ping date de 90 secondes au plus (3 pings manqués), sinon **Déconnecté depuis …** avec l'heure du dernier ping. **Aucun ping reçu** si la table est vide (relais d'une version précédente, ping désactivé, ou refusé) |
| Dernier ping | Heure (fuseau du Dashboard, `app.timezone`) et instance (`RELAY_LABEL`, en-tête `Mailpit-Label`) |
| Latence | Dernière latence, médiane et maximum sur 24 heures |
| Mails en attente / refusés | D'après le dernier ping. « inconnu » si le relais n'a pas pu joindre Mailpit |
| Disponibilité | Part des **créneaux de 5 minutes** qui ont reçu au moins un ping, sur 24 heures, ou depuis le premier ping si l'historique est plus court. Le créneau en cours ne compte pas tant que le relais est connecté |
| Graphique | Latence par créneau de 5 minutes : barre pleine jusqu'à la médiane, barre claire jusqu'au maximum, **bande rouge** quand aucun ping n'est arrivé (déconnexion). Survolez une barre pour le détail |
| Coupures | Chaque intervalle de **plus de 90 secondes** sans ping, du plus récent au plus ancien : début (dernier ping reçu), fin (ping suivant, ou « en cours »), durée |

La page se recharge toute seule chaque minute, sans prolonger la session : laissée ouverte, elle renvoie vers la connexion après `app.session_lifetime` d'inactivité (2 h), comme les autres pages. Le graphique est un SVG calculé par l'API : aucun script, aucune librairie externe (la CSP du Dashboard n'autorise que `dashboard.js`). Il suit le mode sombre du navigateur. Il est décrit en texte pour les lecteurs d'écran.

Les heures sont celles de l'horloge de PHP, en UTC dans la base. Le ping enregistre aussi l'IP du relais (`source_ip`).

> 💡 Une coupure n'est pas forcément une panne de l'API : relais arrêté ou redémarré, serveur Mailpit éteint, réseau coupé. Les mails ne sont pas perdus pour autant : ils attendent dans Mailpit et sont relayés au retour.

## Jetons développeurs

Un développeur reçoit un jeton `mpt_…` et la liste des adresses de test qui lui sont associées. Il l'envoie dans l'en-tête `Authorization: Bearer …`, depuis n'importe quelle IP.

Le jeton voit les mails dont l'un des destinataires (`To`, `Cc` ou `Bcc`) est une de ses adresses. Les adresses sont comparées sans tenir compte des majuscules. Une adresse peut être associée à plusieurs jetons.

### Lister les mails : `GET /v1/messages`

La liste ne donne que des **résumés** : adresses, sujet, extrait (`snippet`), dates, tags et description des pièces jointes. Le corps d'un mail (texte et HTML, jusqu'à 16 Mo chacun) et ses en-têtes se lisent un mail à la fois, avec [`GET /v1/messages/{id}`](#lire-un-mail--get-v1messagesid) : une page de 25 mails complets pourrait dépasser la mémoire allouée à PHP sur l'hébergement.

> ⚠️ Les versions précédentes renvoyaient `text` et `html` dans la liste. Un script qui les y lisait doit maintenant appeler `GET /v1/messages/{id}` avec l'`id` de chaque mail.

| Paramètre | Défaut | Rôle |
|---|---|---|
| `address` | toutes les adresses du jeton | une seule de ses adresses (`403` si elle ne lui est pas associée) |
| `limit` | `5` | nombre de mails renvoyés (1 à 25) |
| `offset` | `0` | nombre de mails à sauter |
| `page` | `1` | numéro de page, à la place d'`offset` : `offset = (page - 1) × limit`. Les deux ensemble : `400` |

```bash
# Le dernier mail reçu par une adresse : son résumé, et son « id » pour lire le mail complet
curl -s 'https://api.amnesic.dev/v1/messages?address=essai@test.mondomaine.fr&limit=1' -H 'Authorization: Bearer mpt_…'

# Les mails 26 à 50 de toutes les adresses du jeton
curl -s 'https://api.amnesic.dev/v1/messages?limit=25&page=2' -H 'Authorization: Bearer mpt_…'
```

```json
{
  "total": 12,
  "count": 1,
  "limit": 1,
  "offset": 0,
  "page": 1,
  "messages": [
    {
      "id": "2qGgGUqCw5N3mAOHzyFGoJ",
      "message_id": "2CJGY3JTeKc0YxSLai06kR@mailpit",
      "from": { "name": "Alice", "address": "alice@exemple.fr" },
      "to": [ { "name": "", "address": "essai@test.mondomaine.fr" } ],
      "cc": [], "bcc": [], "reply_to": [],
      "subject": "Votre code",
      "snippet": "Votre code de confirmation : 482913",
      "return_path": "alice@exemple.fr",
      "tags": [],
      "size": 1053210,
      "attachments": 1,
      "username": null,
      "label": null,
      "date": "2026-11-09T08:31:20.000Z",
      "created_at": "2026-11-09T08:31:20.474Z",
      "received_at": "2026-11-09T08:31:21.205Z",
      "complete": true,
      "truncated": false,
      "parts": [
        { "part_id": "2", "kind": "attachment", "file_name": "devis.pdf", "content_type": "application/pdf",
          "content_id": "", "size": 1048576, "status": "stored" }
      ]
    }
  ]
}
```

| Champ | Contenu |
|---|---|
| `total` | nombre de mails correspondants (toutes pages confondues) · `count` : nombre de mails dans cette réponse |
| `id` | ID du mail dans Mailpit : c'est lui qu'attendent `GET /v1/messages/{id}` et `DELETE /v1/messages/{id}` |
| `snippet` | début du texte du mail (résumé calculé par Mailpit) : suffit parfois pour lire un code court |
| `date` | en-tête `Date` du mail · `created_at` : réception par Mailpit · `received_at` : réception par l'API (UTC, ISO 8601) |
| `complete` | `true` quand toutes les pièces attendues sont enregistrées |
| `truncated` | `true` si le relais a raccourci le mail, trop volumineux pour l'API (texte, HTML, voire en-têtes : voir [Mails trop volumineux](#mails-trop-volumineux--tronqués-par-le-relais)). Le mail complet n'est alors que dans Mailpit |
| `parts[].status` | `stored` (enregistrée), `pending` (pas encore reçue) ou `too_large` (au-delà de la limite, non enregistrée). Le contenu des pièces n'est pas téléchargeable par l'API |
| `label`, `username` | en-tête `Mailpit-Label` (`RELAY_LABEL`) et utilisateur SMTP authentifié, ou `null` |

### Lire un mail : `GET /v1/messages/{id}`

Le mail complet, s'il a été reçu par l'une des adresses du jeton (`To`, `Cc` ou `Bcc`) : les mêmes champs que dans la liste, plus `text`, `html` et `headers`. Sinon `404`, comme pour un mail inconnu : l'API ne dit pas si un mail existe pour d'autres adresses.

```bash
# Ex. pour y lire un code de confirmation (l'« id » vient de la liste)
curl -s 'https://api.amnesic.dev/v1/messages/2qGgGUqCw5N3mAOHzyFGoJ' -H 'Authorization: Bearer mpt_…'
```

```json
{
  "id": "2qGgGUqCw5N3mAOHzyFGoJ",
  "message_id": "2CJGY3JTeKc0YxSLai06kR@mailpit",
  "from": { "name": "Alice", "address": "alice@exemple.fr" },
  "subject": "Votre code",
  "snippet": "Votre code de confirmation : 482913",
  "complete": true,
  "truncated": false,
  "parts": [ { "part_id": "2", "kind": "attachment", "file_name": "devis.pdf", "…": "…" } ],
  "…": "… (tous les champs de la liste)",
  "text": "Votre code de confirmation : 482913\n",
  "html": "<p>Votre code de confirmation : <b>482913</b></p>",
  "headers": { "Subject": [ "Votre code" ], "Content-Type": [ "multipart/mixed; boundary=…" ] }
}
```

| Champ | Contenu |
|---|---|
| `text`, `html` | corps du mail, ou `null` pour un mail reçu par l'ancien webhook direct de Mailpit (résumé seulement) |
| `headers` | en-têtes du mail : nom => liste de valeurs (`{}` si inconnus) |
| `parts` | description des pièces, comme dans la liste : leur contenu n'est pas téléchargeable par l'API |

> 💡 Dans un script : `ID=$(curl -s '…/v1/messages?address=…&limit=1' -H 'Authorization: Bearer mpt_…' | jq -r '.messages[0].id')`, puis `curl -s "…/v1/messages/$ID" …`.

### Ses adresses : `GET /v1/addresses`

```bash
curl -s 'https://api.amnesic.dev/v1/addresses' -H 'Authorization: Bearer mpt_…'
```

```json
{ "count": 2, "addresses": [ { "address": "alice@test.mondomaine.fr", "messages": 3 }, { "address": "essai@test.mondomaine.fr", "messages": 0 } ] }
```

### Supprimer des mails

```bash
# Un mail
curl -s -X DELETE 'https://api.amnesic.dev/v1/messages/2qGgGUqCw5N3mAOHzyFGoJ' -H 'Authorization: Bearer mpt_…'

# Tous les mails d'une adresse (par exemple avant de lancer un test)
curl -s -X DELETE 'https://api.amnesic.dev/v1/addresses?address=essai@test.mondomaine.fr' -H 'Authorization: Bearer mpt_…'
```

La suppression efface le mail de la base et ses pièces jointes de `var/messages/`. Bon à savoir :

- un mail envoyé à **plusieurs** adresses est supprimé pour toutes, y compris pour les autres jetons qui le voyaient ;
- le mail reste dans **Mailpit** : seule la copie de l'API est supprimée ;
- `DELETE /v1/addresses` ne retire pas l'adresse du jeton : seul le Dashboard le fait.

## Rétention : 7 jours et 1000 mails au plus

L'API supprime les mails toute seule, base **et** fichiers (leur ligne dans `mailpit_messages`, leurs pièces dans `mailpit_parts`, leur dossier `var/messages/<mailpit_id>/`) :

| Règle | Réglage | Quand |
|---|---|---|
| Mails reçus par Mailpit il y a plus de 7 jours (colonne `created_at`) | `webhook.max_age_days` (`7`, `0` = jamais) | au nettoyage |
| Au-delà des 1000 mails les plus récents (ordre d'arrivée dans l'API) | `webhook.max_messages` (`1000`, `0` = sans limite) | à chaque **nouveau** mail, et au nettoyage |

**Nettoyage automatique, sans tâche planifiée** : après une requête acceptée du relais (un mail, une pièce jointe, ou son ping de santé toutes les 30 secondes : le nettoyage a donc lieu même quand aucun mail n'arrive), l'API nettoie si le dernier nettoyage date de plus de **15 minutes**. Sa date est dans `var/messages/.maintenance`, verrouillé pendant le nettoyage : deux requêtes simultanées ne nettoient pas en même temps. Une erreur de nettoyage ne fait jamais échouer la requête du relais : elle est écrite dans `var/log/api.log` (`[maintenance] …`), et le nettoyage est retenté 15 minutes plus tard. Chaque nettoyage qui supprime quelque chose y écrit un résumé.

Les suppressions se font par paquets de 200 mails, 1000 au plus par nettoyage automatique. Une grosse purge (après avoir baissé une limite, ou à la mise à jour) continue dès la requête suivante du relais.

Le nettoyage retire aussi ce que personne n'efface, une fois **sans modification depuis 1 heure** (jamais un envoi en cours) :

- les fichiers temporaires `var/messages/.upload-…` d'une pièce jointe dont l'envoi a été interrompu (PHP arrêté, délai dépassé) ;
- les dossiers `var/messages/<ID>/` d'un mail qui n'est plus en base (effacement raté après une suppression) ;
- les fichiers d'un dossier qu'aucune pièce de `mailpit_parts` ne référence.

Un mail supprimé (purge, Dashboard, `DELETE`) **pendant** l'envoi d'une de ses pièces ne laisse pas de fichier : l'API efface la pièce reçue et répond `404`.

Gardez les mêmes valeurs côté Mailpit ([Partie 1, étape 3](../docs/mailpit/01-podman.md#étape-3--créer-le-fichier-de-configuration-de-mailpit)) : `MP_MAX_MESSAGES=1000` et `MP_MAX_AGE=7d`. Mailpit et l'API conservent alors les mêmes mails.

> 💡 Le relais déclenche le nettoyage, ne serait-ce que par son ping de santé toutes les 30 secondes : s'il est arrêté, rien n'est supprimé. Pour nettoyer quand même, lancez **`php bin/cleanup.php`** dans le dossier `api/` de l'hébergement (SSH, s'il est inclus dans votre offre). Il fait tout le nettoyage tout de suite, jusqu'au bout, et affiche un résumé :
>
> ```
> Nettoyage terminé :
>   Mails de plus de 7 jours supprimés  : 12
>   Mails au-delà des 1000 plus récents : 0
>   Fichiers temporaires effacés        : 1
>   Dossiers sans mail en base effacés  : 0
>   Fichiers sans pièce en base effacés : 0
> ```
>
> Pour l'automatiser (facultatif) : envoyez `bin/cleanup.php` en FTP, puis Espace client OVH → **Hébergements** → onglet **Tâches planifiées - Cron** (parfois sous **Plus**) → **Ajouter une planification** : commande `api/bin/cleanup.php` (chemin depuis la racine FTP), langage PHP 8.4 ou plus, une fois par jour. Le script refuse d'être appelé depuis le web (`404`, et `bin/` est bloqué par le `.htaccess`).

Pour supprimer un mail à la main, supprimez sa ligne dans `mailpit_messages` (ses lignes de `mailpit_parts` et `mailpit_message_recipients` partent avec elle) : son dossier `var/messages/<mailpit_id>/` est effacé par un nettoyage suivant, une fois resté 1 heure sans modification. Plus simple : un jeton développeur associé à son adresse peut le supprimer, base et fichiers (`DELETE /v1/messages/{id}`).

## Logs : rotation à 10 Mo

Les erreurs PHP et les requêtes refusées (`[auth]`, `[ip-filter]`, `[https]`, `[signin]`…) sont écrites dans `log_file`, `var/log/api.log` par défaut. Les robots qui scannent Internet le remplissent vite : l'API l'archive toute seule.

**Rotation** : quand `api.log` atteint **10 Mo** (`log_max_size`), il est renommé en `api.log.1`, et un nouveau `api.log` repart de zéro. L'archive précédente est remplacée. `var/log/` ne contient donc jamais plus de deux logs d'environ 10 Mo. Rien à vider à la main, rien à planifier.

| Fichier | Contenu |
|---|---|
| `var/log/api.log` | le log en cours |
| `var/log/api.log.1` | l'archive : le log d'avant la dernière rotation |
| `var/log/api.log.lock` | fichier vide : verrou de la rotation |

- **Quand ?** Au début de chaque requête, avant toute écriture dans le log : un simple `stat` du fichier, sans coût notable. `bin/cleanup.php` fait la même vérification.
- **Requêtes simultanées.** Une seule archive le fichier (verrou sur `api.log.lock`, sans attente), puis vérifie de nouveau sa taille une fois le verrou obtenu. Les autres voient le nouveau fichier : l'archive n'est jamais remplacée par un log presque vide.
- **Erreur.** Si le renommage est impossible (droits de `var/log/`), la requête est servie normalement et le log continue dans le même fichier, avec une ligne `[log] archivage de « … » en « … » impossible` à chaque requête.
- **Protection.** Les trois fichiers sont inaccessibles depuis le web : le dossier `var/` est interdit, et le `.htaccess` refuse aussi les noms en `.log`, `.log.1` et `.lock`.

> 💡 Pour garder plus d'historique, augmentez `log_max_size` (ex. `50 * 1024 * 1024` : deux fichiers d'environ 50 Mo). `0` désactive la rotation : déconseillé, le log grossit alors sans limite.

## HTTPS obligatoire

Le jeton du relais, les jetons développeurs, le mot de passe et le cookie du Dashboard ne circulent jamais en clair. Deux protections, l'une derrière l'autre :

| Où | Requête en `http://` | Réponse en HTTPS |
|---|---|---|
| `.htaccess` (racine **et** `public/`, pour les deux dossiers racines possibles) | `GET` / `HEAD` : `301` vers la même URL en `https://`. Les autres méthodes passent à PHP | `Strict-Transport-Security: max-age=31536000` (`mod_headers`) |
| PHP (`ForceHttpsMiddleware`), même si `mod_rewrite` est absent | `GET` / `HEAD` : `301`. Autres méthodes : `403` JSON, **sans lire le corps** (rediriger un `POST` le ferait renvoyer en clair, ou changer en `GET`) | Même en-tête HSTS |

- **Détection.** Une requête est en HTTPS si Apache indique `HTTPS=on` ou le port `443`. Chez OVH, le port est le signe fiable (méthode recommandée par OVH : `%{SERVER_PORT}` dans le `.htaccess`), `HTTPS` n'étant pas toujours fixé derrière leur frontal. Derrière un [proxy de confiance](#derrière-un-proxy-ou-un-cdn), c'est l'en-tête `X-Forwarded-Proto` qui décide.
- **HSTS.** Après une première visite en HTTPS, le navigateur n'essaie plus `http://` pendant un an, même si on tape l'adresse sans `https://`. Pas de `preload` : l'inscription dans les navigateurs serait difficile à annuler.
- **Journal.** Une requête refusée est écrite dans `var/log/api.log` (`[https] requête en clair refusée depuis « IP » : POST /v1/messages`). Si elle portait un en-tête `Authorization` (`GET` redirigé compris), le log l'indique, sans la valeur : ce jeton a circulé sans chiffrement, changez-le.
- **Cookie de session.** Le cookie du Dashboard est marqué `Secure` d'après la même détection : il n'est jamais envoyé en clair. L'exemple `curl` de la page d'un jeton suit la même détection : il est en `https://`, même derrière le frontal OVH.
- **Relais.** `RELAY_API_URL` doit commencer par `https://` : le relais refuse de démarrer sinon (`http://` n'est accepté que vers `localhost`, pour des tests).

> ⚠️ Activez le certificat SSL de l'hébergement **avant** : voir l'[étape 4](#4-brancher-le-domaine-apiamnesicdev).

> 💡 **Développement local** (`php -S`, `http://localhost:8080`) : mettez `'force_https' => false` dans `config.php`. Plus de redirection, de `403` ni d'en-tête HSTS côté PHP, et le cookie du Dashboard n'est plus `Secure` en `http://`. Les `.htaccess`, eux, redirigent toujours (`php -S` ne les lit pas). Ne le faites jamais sur l'hébergement.

## Derrière un proxy ou un CDN

Sur un hébergement OVH sans option CDN, l'IP du client est dans `REMOTE_ADDR` : laissez `proxy.trusted_proxies` vide. Cette IP sert au filtre du relais et à la limitation des connexions au Dashboard (par adresse), et apparaît dans leur journal. Si vous activez un CDN devant l'API, listez ses adresses dans `proxy.trusted_proxies`. L'IP réelle sera alors lue dans `X-Forwarded-For`, mais **uniquement** pour les requêtes qui viennent de ces adresses. Sans cette précaution, n'importe qui pourrait envoyer un faux en-tête pour se faire passer pour le serveur Mailpit.

Il en va de même pour le protocole : l'en-tête `X-Forwarded-Proto` (`https` ou `http`, la dernière valeur s'il y en a plusieurs) n'est lu que pour ces adresses. Un CDN qui joint l'hébergement en `http://` **doit** donc être listé, sinon chaque page est redirigée vers `https://` à l'infini (« Trop de redirections »). Le `.htaccess` laisse passer à PHP une requête qui annonce `X-Forwarded-Proto: https` : c'est PHP qui vérifie qu'elle vient bien d'un proxy de confiance.
