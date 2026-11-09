# API webhook Mailpit

Petite API **PHP 8.5 / Slim 4 / MySQL 8.4** pour un hébergement **OVH Perso**. Elle reçoit chaque mail arrivé dans Mailpit et l'enregistre : le message en base MySQL, les pièces jointes en fichiers.

Elle comprend aussi :

- un **Dashboard** pour les administrateurs (`/v1/app/…`) : liste de tous les mails, gestion des jetons développeurs ;
- des **jetons développeurs** : chacun lit (et supprime) par l'API les mails des adresses qui lui sont associées.

```
relais (serveur Mailpit) ── 1. POST /v1/messages                     mail en JSON ──► table mailpit_messages
        Authorization:  ◄── réponse : pièces attendues ("missing")
        Bearer <jeton>  ── 2. POST /v1/messages/{id}/part/{partId}   une requête par pièce ──► var/messages/{id}/…
                        ◄── réponse : "complete": true quand tout est enregistré  + table mailpit_parts

développeurs ── GET /v1/messages, GET /v1/addresses…    jeton « mpt_… » créé dans le Dashboard
administrateurs ── https://api.amnesic.dev/v1/app/signin  Dashboard (identifiant + mot de passe)
```

Trois sortes d'accès :

| Qui | Comment | Peut |
|---|---|---|
| **Relais** | IP dans `webhook.allowed_ips` (sinon `403`) **et** jeton `webhook.token` (sinon `401`). Ce jeton est configuré des deux côtés : dans `config.php` ici, et dans `RELAY_API_TOKEN` côté relais | **Seul** à pouvoir envoyer des mails (`POST`). Peut aussi lister tous les mails (`GET /v1/messages`) |
| **Développeurs** | Jeton `mpt_…` créé dans le Dashboard, depuis n'importe quelle IP | Lire et supprimer les mails des adresses associées à son jeton |
| **Administrateurs** | Identifiant et mot de passe (table `mailpit_admins`), depuis n'importe quelle IP. Sur 30 minutes : 3 essais par identifiant depuis une même adresse, 10 par adresse tous identifiants confondus | Voir tous les mails, créer et supprimer les jetons, leur associer des adresses |

Le mail complet est envoyé par le [relais](../relay/README.md), installé sur le serveur Mailpit ([Partie 7 du tutoriel](../docs/mailpit/07-relay.md)) : en-têtes, texte, HTML, pièces jointes et images intégrées. Le relais ne garde rien de son côté : il ne considère un mail comme livré que lorsque l'API répond `"complete": true`.

> ℹ️ L'API accepte aussi le webhook **direct** de Mailpit (sans relais), qui n'envoie qu'un **résumé** : expéditeur, destinataires, sujet, date, taille, nombre de pièces jointes, tags et les 250 premiers caractères du texte (`Snippet`). Voir l'[étape 5](#5-configurer-mailpit).

## Arborescence

```
api/
├── .htaccess            # redirige tout vers public/ et bloque les fichiers sensibles
├── .ovhconfig           # version de PHP sur l'hébergement OVH
├── config.example.php   # modèle de configuration → à copier en config.php
├── composer.json / composer.lock
├── phpunit.xml.dist     # configuration des tests automatisés (PHPUnit)
├── bin/
│   └── create-admin.php # prépare la requête SQL qui crée un administrateur
├── public/
│   ├── .htaccess        # contrôleur frontal Slim
│   └── index.php
├── sql/
│   ├── schema.sql       # tables MySQL, à importer une fois
│   └── migrations/      # mises à jour d'une base créée avec une version précédente
├── src/                 # code de l'API
├── templates/           # pages HTML du Dashboard
├── tests/               # tests automatisés (voir « Tests automatisés »), inutiles sur l'hébergement
├── var/log/             # logs (doit être accessible en écriture)
├── var/messages/        # pièces jointes et images intégrées, un dossier par mail (accessible en écriture)
└── vendor/              # généré par composer install, à envoyer en FTP
```

## Routes

### Relais

Elles exigent le jeton du relais : en-tête `Authorization: Bearer <jeton>` (relais), ou `?token=<jeton>` dans l'URL (webhook direct de Mailpit, qui ne sait pas ajouter d'en-tête). Et l'appel doit venir d'une IP de `webhook.allowed_ips`.

| Méthode | URL | Réponse |
|---|---|---|
| `POST` | `/v1/messages` | Corps : le mail en JSON (voir ci-dessous).<br>`201 {"status":"created", "id":"…", "complete":false, "missing":["2","1.3"], "too_large":["4"]}` : message enregistré<br>`200 {"status":"updated"\|"duplicate", …}` : déjà reçu (même ID Mailpit), complété si besoin<br>`400` JSON invalide · `413` trop volumineux · `415` pas du JSON · `422` champ invalide |
| `POST` | `/v1/messages/{id}/part/{partId}` | Corps : le contenu brut de la pièce (`Content-Type: application/octet-stream`), avec l'en-tête `X-Content-SHA256` (empreinte SHA-256 en hexadécimal).<br>`201 {"status":"stored", "complete":true, "missing":[], …}` : pièce enregistrée<br>`200 {"status":"already-stored", …}` : déjà reçue<br>`404` message ou pièce inconnus · `409` pièce `too_large`, ou déjà reçue avec un autre contenu · `413` au-delà de la limite · `415` mauvais Content-Type · `422` empreinte absente ou différente |

Pour les deux : `403` IP non autorisée (vérifiée en premier) · `401` jeton absent ou faux · `500` erreur serveur (base injoignable…).

### Développeurs

Elles exigent un jeton développeur, créé dans le Dashboard : en-tête `Authorization: Bearer mpt_…` (jamais dans l'URL). Le jeton ne voit que les mails dont l'un des destinataires (`To`, `Cc` ou `Bcc`) est une des adresses qui lui sont associées. Détail et exemples : [Jetons développeurs](#jetons-développeurs).

| Méthode | URL | Réponse |
|---|---|---|
| `GET` | `/v1/messages` | Les mails de ses adresses, du plus récent au plus ancien, **avec leur corps** (texte et HTML). 5 par défaut.<br>Paramètres : `address`, `limit` (25 au maximum), `offset` ou `page`.<br>`200 {"total":…, "count":…, "limit":5, "offset":0, "page":1, "messages":[…]}`<br>`400` paramètre invalide · `403` adresse non associée au jeton |
| `DELETE` | `/v1/messages/{id}` | Supprime ce mail.<br>`200 {"id":"…", "deleted":true}` · `404` mail inconnu, ou reçu par aucune de ses adresses |
| `GET` | `/v1/addresses` | Ses adresses et leur nombre de mails.<br>`200 {"count":2, "addresses":[{"address":"alice@test.mondomaine.fr", "messages":3}, …]}` |
| `DELETE` | `/v1/addresses?address=…` | Supprime tous les mails reçus par cette adresse (elle reste associée au jeton).<br>`200 {"address":"…", "deleted":3}` · `400` `address` absent · `403` adresse non associée au jeton |

Pour toutes : `401` jeton absent, inconnu ou supprimé.

`GET /v1/messages` accepte aussi le **jeton du relais**, depuis les IP de `webhook.allowed_ips` uniquement (sinon `403`) : il voit alors tous les mails. Pratique pour les tests depuis le serveur Mailpit ([Partie 8](../docs/mailpit/08-tests-relay-api.md)).

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

Toute autre URL renvoie `404`.

**Champs envoyés par le relais**, en plus du résumé de Mailpit (`ID`, `MessageID`, `From`, `To`, `Cc`, `Bcc`, `ReplyTo`, `Subject`, `Created`, `Size`, `Attachments`, `Tags`, `Snippet`…) :

| Champ | Enregistré dans |
|---|---|
| `Text`, `HTML` | colonnes `text_body`, `html_body` |
| `Headers` (`{"Nom": ["valeur", …]}`) | colonne `headers` |
| `ReturnPath`, `Date` | colonnes `return_path`, `message_date` |
| `Parts` : `[{"PartID":"2", "Kind":"attachment", "FileName":"devis.pdf", "ContentType":"application/pdf", "ContentID":"", "Size":123456}, …]` | table `mailpit_parts` (une ligne par pièce). `Kind` vaut `attachment` (pièce jointe) ou `inline` (image intégrée au HTML, `cid:…`) |

La colonne `payload` garde le JSON reçu **sans** `Text`, `HTML` ni `Headers`, pour ne pas les stocker deux fois.

**Pièces jointes.** Le JSON ne contient que leur description. L'API répond avec la liste `missing` des pièces dont elle attend le contenu, que le relais envoie ensuite une par une (`POST /v1/messages/{id}/part/{partId}`). Chaque fichier est vérifié (SHA-256), écrit sur le disque, puis la ligne de `mailpit_parts` passe à l'état `stored`. Le message est **complet** (colonne `completed_at` remplie, `"complete": true`) quand plus aucune pièce n'est attendue.

Une pièce plus grosse que `webhook.max_attachment_size` (**5 Mo** par défaut) n'est pas attendue : sa ligne est enregistrée avec l'état `too_large` (nom, type, taille), sans contenu, et elle est listée dans `too_large`.

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
| `app.session_lifetime` | Dashboard : déconnexion après ce nombre de secondes d'inactivité (2 h par défaut) |
| `app.timezone` | Dashboard : fuseau horaire d'affichage des dates (`Europe/Paris` par défaut) |
| `debug` | `false` en production |

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

Espace client OVH → **Bases de données** → **…** → **Aller sur phpMyAdmin**. Sélectionnez la base dans la colonne de gauche, puis onglet **Importer** → fichier `sql/schema.sql`. Il crée les tables `mailpit_messages`, `mailpit_parts` et `mailpit_message_recipients` (mails), `mailpit_admins` et `mailpit_login_attempts` (Dashboard), `mailpit_tokens` et `mailpit_token_addresses` (jetons développeurs).

> **Mise à jour d'une installation existante** : importez plutôt les fichiers de `sql/migrations/`, dans l'ordre, en sautant ceux déjà importés. Chacun peut être importé plusieurs fois sans risque.
>
> | Fichier | Pour une base créée… | Ce qu'il fait |
> |---------|----------------------|---------------|
> | `2026-11-dashboard-tokens.sql` | avant l'arrivée du Dashboard | Crée les nouvelles tables et y range les destinataires des mails déjà enregistrés |
> | `2026-11-login-throttle-ip.sql` | avant la limitation des connexions par adresse | Ajoute la colonne `ip_key` et ses index à `mailpit_login_attempts` |

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

Envoyez aussi `templates/` (pages et script du Dashboard). Inutile d'envoyer `README.md`, `bin/`, `sql/`, `tests/`, `phpunit.xml.dist` et `config.example.php` (ils sont de toute façon bloqués par le `.htaccess`).

Les dossiers `var/log/` et `var/messages/` doivent être accessibles en écriture par PHP (droits `755` ou `705`, ce qui est le cas par défaut chez OVH). `var/messages/` contiendra toutes les pièces jointes : surveillez l'espace disque de l'hébergement.

### 4. Brancher le domaine `api.amnesic.dev`

Espace client OVH → **Hébergements** → onglet **Multisite** → **Ajouter un domaine ou sous-domaine** → `api.amnesic.dev`, puis :

- **Dossier racine** : `api/public` (recommandé : seul `public/` est alors visible depuis le web).
  `api` fonctionne aussi : le `.htaccess` racine redirige vers `public/` et bloque le reste.
- Cochez **SSL** et attendez que le certificat soit généré. Mailpit appellera l'API en HTTPS.

**Version de PHP** : le fichier `.ovhconfig` demande PHP 8.5. OVH le lit à la racine de l'hébergement ou à la racine du dossier du multisite. Si vous choisissez `api/public` comme dossier racine, **déplacez `.ovhconfig` dans `public/`**. Si OVH ne propose pas encore la 8.5, mettez `8.4` (le minimum de l'API). Vérifiez la version active dans l'onglet **Informations générales** de l'hébergement.

### 5. Configurer Mailpit

**Avec le relais** (mail complet, pièces jointes comprises : recommandé) : suivez la [Partie 7 du tuto](../docs/mailpit/07-relay.md). `https://api.amnesic.dev` va dans `RELAY_API_URL`, le jeton dans `RELAY_API_TOKEN`, et Mailpit appelle le relais.

**Sans relais** (résumé seulement, aucun nouvel essai si l'API est injoignable) : le détail est dans la [Partie 6 du tuto](../docs/mailpit/06-webhook.md). En résumé, sur le serveur Debian, ajoutez à `/etc/mailpit/mailpit.env` :

```ini
# Webhook appelé à chaque nouveau mail (Mailpit ne sait pas envoyer d'en-tête : jeton dans l'URL)
MP_WEBHOOK_URL=https://api.amnesic.dev/v1/messages?token=LE_JETON_DE_CONFIG_PHP

# 0 = pas de limite. Par défaut Mailpit n'envoie qu'UN webhook par seconde
# et IGNORE les mails reçus entre-temps : ils n'arriveraient jamais dans l'API.
MP_WEBHOOK_LIMIT=0

# Facultatif : nom envoyé dans l'en-tête Mailpit-Label (colonne mailpit_label)
# MP_LABEL=mailpit-prod
```

Puis :

```bash
sudo systemctl restart mailpit
```

> ℹ️ Mailpit n'essaie **qu'une seule fois** (délai d'attente de 5 secondes). Si l'API est injoignable à ce moment-là, le mail reste dans Mailpit mais n'arrive pas en base. Les échecs apparaissent dans `sudo journalctl -u mailpit | grep webhook`.

---

## Tester

**Depuis le serveur Mailpit** (le seul autorisé), un appel manuel. Pour une recette complète, pas à pas, voir la [Partie 8 du tutoriel](../docs/mailpit/08-tests-relay-api.md) :

```bash
curl -4 -i -X POST 'https://api.amnesic.dev/v1/messages' \
  -H 'Authorization: Bearer LE_JETON_DE_CONFIG_PHP' \
  -H 'Content-Type: application/json' \
  -d '{"ID":"test-manuel-1","Created":"2026-01-01T12:00:00Z","Subject":"Test manuel","From":{"Name":"","Address":"moi@exemple.fr"},"To":[],"Parts":[{"PartID":"2","Kind":"attachment","FileName":"test.txt","ContentType":"text/plain","ContentID":"","Size":5}]}'
```

Réponse attendue : `HTTP/2 201` puis `{"id":"test-manuel-1","status":"created","complete":false,"missing":["2"],"too_large":[]}`. Envoyez ensuite la pièce annoncée :

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

Réponse attendue : `HTTP/2 201` et `"complete":true`. Le fichier est dans `var/messages/test-manuel-1/part-2`. Supprimez ensuite la ligne de test dans phpMyAdmin (sa pièce est supprimée avec elle) et le dossier `var/messages/test-manuel-1/` par FTP.

Puis la liste :

```bash
curl -4 -s 'https://api.amnesic.dev/v1/messages' -H 'Authorization: Bearer LE_JETON_DE_CONFIG_PHP'
```

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

**Fichiers protégés** : `https://api.amnesic.dev/config.php`, `/vendor/autoload.php`, `/.ovhconfig`… doivent tous renvoyer `403` ou `404`.

---

## Tests automatisés

Les tests **PHPUnit** vérifient le code sur votre ordinateur, sans rien déployer. Ils ne remplacent pas les essais ci-dessus, qui vérifient l'installation réelle (hébergement, DNS, `.htaccess`…).

| Suite | Dossier | Ce qui est testé | Base MySQL |
|---|---|---|---|
| `unit` | `tests/Unit/` | classes sans base : filtre IP (`IpMatcher`, `ClientIpResolver`), adresses, validation du JSON des mails et des pièces (chaque erreur `422`), configuration (`Settings`), filtres du Dashboard, jetons (`ApiScope`, `Authorization: Bearer`), adresse comptée par la limite de connexion (`LoginThrottle::ipKey`) | non |
| `integration` | `tests/Integration/` | accès à la base : mails, pièces, purge, liste par adresse, suppression, jetons développeurs, limite de connexion au Dashboard (par identifiant et adresse, plafond par adresse, IPv6 par `/64`) | oui |
| `http` | `tests/Http/` | l'application complète (`Kernel::create`) avec de fausses requêtes : routes du relais (`403`, `401`, `415`, `400`, `413`, `422`, `201`, `200`), envoi des pièces jusqu'à `"complete": true`, routes des développeurs, Dashboard (redirection vers la connexion, refus sans jeton anti-CSRF, messages de blocage) | oui |

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
| `401` | Jeton absent ou différent de `webhook.token` (`[auth] jeton absent` ou `[auth] jeton invalide` dans `var/log/api.log`). Vérifiez `RELAY_API_TOKEN` (relais) ou `MP_WEBHOOK_URL` (webhook direct) |
| `401` avec `jeton absent` alors que le relais envoie bien le jeton | L'en-tête `Authorization` n'arrive pas jusqu'à PHP : vérifiez que les `.htaccess` (racine et `public/`) ont bien été envoyés, à jour |
| `500` avec `{"error":"Erreur de configuration du serveur"}` | `vendor/` ou `config.php` absent, ou `config.php` invalide (détail dans `var/log/api.log`). Souvent : `webhook.token` vide ou trop court (**obligatoire**, 32 caractères minimum) |
| `500` avec `Slim Application Error` | Souvent la base : identifiants faux ou table absente. Détail dans `var/log/api.log` |
| `404` sur `/v1/messages` | `.htaccess` non envoyés (fichiers cachés), ou mauvais dossier racine du multisite. Si l'API est dans un sous-dossier de l'URL, renseignez `base_path` |
| Certains mails manquent en base | `MP_WEBHOOK_LIMIT` n'est pas à `0` (voir l'étape 5) |
| `413` sur `POST /v1/messages` | Le JSON du mail (texte + HTML + en-têtes) dépasse `webhook.max_body_size` (16 Mo par défaut) : augmentez-le |
| `413` sur `POST /v1/messages/…/part/…` | La pièce dépasse la limite, alors que sa taille annoncée était plus petite : cas anormal, voir les logs du relais |
| Log PHP `POST Content-Length of … exceeds the limit` | Un mail au texte ou HTML très lourd, ou une pièce jointe, dépasse `post_max_size` de l'hébergement. Le JSON est quand même lu et enregistré : l'avertissement est sans effet tant que `display_errors` est désactivé (`environment=production` dans `.ovhconfig`) |
| Pièce jointe en `too_large` | Elle dépasse `webhook.max_attachment_size` (5 Mo par défaut). Seule sa description est enregistrée |
| Des mails anciens ont disparu | Normal : seuls les `webhook.max_messages` derniers (1000 par défaut) sont gardés. Les échecs de purge sont écrits dans `var/log/api.log` (`[prune] …`) |
| `text_body` toujours vide | Le webhook ne passe pas par le relais (`MP_WEBHOOK_URL` pointe directement sur l'API), ou `sql/schema.sql` n'a pas été importé (erreur `Table … doesn't exist` dans le log) |
| Mail avec `completed_at` vide | Des pièces sont encore attendues (`status = 'pending'` dans `mailpit_parts`). Le relais les renvoie tout seul : voir ses logs |
| Page blanche / erreur PHP | Version de PHP trop ancienne : vérifiez `.ovhconfig` (8.4 minimum) |
| Dashboard : « Trop de tentatives pour cet identifiant depuis votre adresse » | 3 échecs en moins de 30 minutes pour cet identifiant depuis votre adresse : attendez la fin du délai affiché, ou connectez-vous depuis une autre adresse. Pour débloquer tout de suite : `DELETE FROM mailpit_login_attempts WHERE username = 'identifiant';` dans phpMyAdmin. Les échecs sont dans `var/log/api.log` (`[signin] …`) |
| Dashboard : « Trop de tentatives depuis votre adresse » | 10 échecs en moins de 30 minutes depuis votre adresse, tous identifiants confondus (IPv6 : tout le `/64`). Pour débloquer tout de suite : `DELETE FROM mailpit_login_attempts WHERE ip_key = 'adresse';` (adresse IPv4, ou préfixe comme `2001:db8:1:2::/64`, écrit entre parenthèses après l'adresse IPv6 dans le log `[signin] …`) |
| Dashboard : « Formulaire expiré : recommencez » | La page est restée ouverte trop longtemps (session expirée), ou les cookies sont bloqués pour le domaine |
| Dashboard : erreur `500` avec `Table … doesn't exist` dans le log | La migration `sql/migrations/2026-11-dashboard-tokens.sql` n'a pas été importée |
| Dashboard : erreur `500` à la connexion, avec `Field 'ip_key' doesn't have a default value` dans le log | La migration `2026-11-login-throttle-ip.sql` a été importée, mais les fichiers de l'API n'ont pas encore été mis à jour : envoyez la nouvelle version en FTP (au moins `src/` et `templates/`) |
| Dashboard : erreur `500` à la connexion, avec `Unknown column 'ip_key'` dans le log | La migration `sql/migrations/2026-11-login-throttle-ip.sql` n'a pas été importée |
| Jeton développeur : `401` | Jeton faux, supprimé, ou envoyé ailleurs que dans l'en-tête `Authorization: Bearer …` (`[api-auth] …` dans `var/log/api.log`) |
| Jeton développeur : `"total": 0` alors que des mails existent | Aucune adresse associée au jeton, ou l'adresse ne correspond à aucun destinataire `To` / `Cc` / `Bcc` |
| Jeton développeur : `403` | `?address=` désigne une adresse qui n'est pas associée au jeton |

Le fichier `var/log/api.log` n'est pas vidé automatiquement : supprimez-le de temps en temps par FTP.

> ℹ️ Avec le relais, le jeton voyage dans un en-tête : il n'apparaît pas dans les logs d'accès de l'hébergement. Avec le webhook direct (`?token=…`), il fait partie de l'URL et apparaît dans ces logs (visibles uniquement depuis votre espace client OVH) : changez-le si vous les partagez.

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
```

## Dashboard

`https://api.amnesic.dev/v1/app/signin`, avec un compte de la table `mailpit_admins` ([étape 2 bis](#2-bis-créer-un-administrateur-du-dashboard)).

- **Messages** : tous les mails enregistrés, 50 par page : date de réception, expéditeur, destinataires (`To`, `Cc`, `Bcc`), sujet et début du texte, nombre de pièces jointes. Un mail dont des pièces sont encore attendues est marqué « incomplet ». Deux filtres :
  - **Adresses** : liste déroulante de toutes les adresses ayant reçu un mail (`To`, `Cc` ou `Bcc`), avec leur nombre de mails. Cochez-en une ou plusieurs puis **Filtrer** : seuls les mails reçus par l'une d'elles restent ;
  - **Ordre** : du plus récent au plus ancien (par défaut) ou l'inverse. Un clic sur l'en-tête de colonne « Reçu le » inverse aussi l'ordre.

  Les filtres sont dans l'URL : la pagination et le retour depuis un mail les conservent, et une liste filtrée peut être mise en favori.
  **Suppression** : cochez des mails (la case de l'en-tête coche toute la page), puis **Supprimer la sélection (N)**. Le navigateur demande confirmation. On revient ensuite sur la même liste, mêmes filtres.
- **Un mail** (clic sur sa date ou son sujet), en trois onglets :
  - **Mail** : expéditeur, destinataires (`To`, `Cc`, `Bcc`), `Répondre à`, dates (en-tête `Date`, réception par Mailpit et par l'API), taille, tags, identifiants, puis le contenu en **version texte**. Le bouton **HTML** affiche la version HTML à la place (seule version affichée si le mail n'a pas de texte) ;
  - **Pièces jointes (N)** : pièces jointes et images intégrées, avec un lien **Télécharger** (nom de fichier d'origine). Une pièce trop volumineuse (`too_large`) ou pas encore reçue est listée, sans lien ;
  - **En-têtes (N)** : tous les en-têtes du mail, dans l'ordre.

  Chaque onglet a sa propre URL. Le bouton **Supprimer ce mail**, au-dessus des onglets, le supprime après confirmation et ramène à la liste.

Une suppression efface le mail de la base (pièces jointes et destinataires compris) et ses fichiers dans `var/messages/`, comme `DELETE /v1/messages/{id}` côté jetons. Le mail reste dans **Mailpit**. Si le relais l'y renvoyait (mail pas encore complet au moment de la suppression), il réapparaîtrait.
- **Tokens** : création (un nom suffit) et suppression des jetons développeurs. Un jeton supprimé est refusé (`401`) dès l'appel suivant.
- **Détail d'un jeton** : associer ou retirer des adresses, et voir combien de mails chacune a reçus.

**Affichage du HTML d'un mail.** Le HTML vient de l'expéditeur : il est affiché dans un cadre isolé (`iframe` + CSP `sandbox`), où aucun script ni formulaire ne fonctionne, et sans accès à la session du Dashboard. Les images intégrées (`cid:…`) y sont incluses directement. Les **images distantes sont bloquées** par défaut : elles diraient à l'expéditeur que le mail a été ouvert (pixel de suivi). Le lien **Afficher les images distantes** les autorise pour ce mail. Les liens s'ouvrent dans un nouvel onglet.

> ⚠️ **Le jeton n'est affiché qu'une fois**, juste après sa création. Seule son empreinte SHA-256 est gardée en base : s'il est perdu, supprimez-le et créez-en un autre.

**Sécurité.** Les pages n'exécutent qu'un seul script, `dashboard.js`, servi par l'API (CSP `script-src 'self'`) : il affiche les confirmations et gère les cases à cocher. Connexion limitée, sur 30 minutes, à **3 échecs par identifiant depuis une même adresse** et à **10 échecs par adresse**, tous identifiants confondus. Au-delà, la connexion est refusée (`429`, en-tête `Retry-After`) jusqu'à 30 minutes après le plus ancien des échecs comptés, même avec le bon mot de passe. Le blocage d'un identifiant ne vaut que pour l'adresse qui a échoué : quelqu'un d'autre ne peut pas bloquer votre compte. Une IPv4 est comptée à l'adresse exacte, une IPv6 par préfixe `/64` (sinon il suffirait de changer d'adresse dans son `/64`). Une connexion réussie remet à zéro le compteur de cet identifiant depuis cette adresse. Un identifiant inexistant est traité de la même façon, pour ne pas révéler quels comptes existent. Derrière un proxy, l'adresse est celle du client, déterminée comme pour le filtre IP (`proxy.trusted_proxies`). Les autres protections : mots de passe hachés (`password_hash`), cookie de session `HttpOnly`, `SameSite=Strict` et `Secure` en HTTPS, limité au chemin `/v1/app`, jeton anti-CSRF dans chaque formulaire, déconnexion après 2 h d'inactivité (`app.session_lifetime`), pages jamais mises en cache ni affichables dans une iframe.

Le Dashboard est accessible depuis **n'importe quelle IP** : `webhook.allowed_ips` ne concerne que le relais.

## Jetons développeurs

Un développeur reçoit un jeton `mpt_…` et la liste des adresses de test qui lui sont associées. Il l'envoie dans l'en-tête `Authorization: Bearer …`, depuis n'importe quelle IP.

Le jeton voit les mails dont l'un des destinataires (`To`, `Cc` ou `Bcc`) est une de ses adresses. Les adresses sont comparées sans tenir compte des majuscules. Une adresse peut être associée à plusieurs jetons.

### Lire les mails : `GET /v1/messages`

| Paramètre | Défaut | Rôle |
|---|---|---|
| `address` | toutes les adresses du jeton | une seule de ses adresses (`403` si elle ne lui est pas associée) |
| `limit` | `5` | nombre de mails renvoyés (1 à 25) |
| `offset` | `0` | nombre de mails à sauter |
| `page` | `1` | numéro de page, à la place d'`offset` : `offset = (page - 1) × limit`. Les deux ensemble : `400` |

```bash
# Le dernier mail reçu par une adresse (ex. pour y lire un code de confirmation)
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
      "parts": [
        { "part_id": "2", "kind": "attachment", "file_name": "devis.pdf", "content_type": "application/pdf",
          "content_id": "", "size": 1048576, "status": "stored" }
      ],
      "text": "Votre code de confirmation : 482913\n",
      "html": "<p>Votre code de confirmation : <b>482913</b></p>"
    }
  ]
}
```

| Champ | Contenu |
|---|---|
| `total` | nombre de mails correspondants (toutes pages confondues) · `count` : nombre de mails dans cette réponse |
| `id` | ID du mail dans Mailpit : c'est lui qu'attend `DELETE /v1/messages/{id}` |
| `date` | en-tête `Date` du mail · `created_at` : réception par Mailpit · `received_at` : réception par l'API (UTC, ISO 8601) |
| `text`, `html` | corps du mail, ou `null` s'il n'a pas été envoyé par le relais (webhook direct de Mailpit) |
| `complete` | `true` quand toutes les pièces attendues sont enregistrées |
| `parts[].status` | `stored` (enregistrée), `pending` (pas encore reçue) ou `too_large` (au-delà de la limite, non enregistrée). Le contenu des pièces n'est pas téléchargeable par l'API |
| `label`, `username` | en-tête `Mailpit-Label` (`RELAY_LABEL`) et utilisateur SMTP authentifié, ou `null` |

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

## Rétention : les 1000 derniers mails

À chaque **nouveau** mail, l'API supprime les plus anciens pour n'en garder que `webhook.max_messages` (1000 par défaut) : leur ligne dans `mailpit_messages`, leurs pièces dans `mailpit_parts`, et leur dossier `var/messages/<mailpit_id>/`. « Les plus anciens » s'entend dans l'ordre d'arrivée dans l'API. Une grosse purge (par exemple après avoir baissé la limite) se fait par paquets de 200 mails, au fil des mails suivants.

Gardez la même valeur que `MP_MAX_MESSAGES` côté Mailpit ([Partie 1, étape 3](../docs/mailpit/01-podman.md#étape-3--créer-le-fichier-de-configuration-de-mailpit)) : Mailpit et l'API conservent alors les mêmes mails.

Pour supprimer un mail à la main, supprimez sa ligne dans `mailpit_messages` (ses lignes de `mailpit_parts` et `mailpit_message_recipients` partent avec elle) et son dossier `var/messages/<mailpit_id>/` par FTP. Plus simple : un jeton développeur associé à son adresse peut le supprimer, base et fichiers (`DELETE /v1/messages/{id}`).

## Derrière un proxy ou un CDN

Sur un hébergement OVH sans option CDN, l'IP du client est dans `REMOTE_ADDR` : laissez `proxy.trusted_proxies` vide. Cette IP sert au filtre du relais et à la limitation des connexions au Dashboard (par adresse), et apparaît dans leur journal. Si vous activez un CDN devant l'API, listez ses adresses dans `proxy.trusted_proxies`. L'IP réelle sera alors lue dans `X-Forwarded-For`, mais **uniquement** pour les requêtes qui viennent de ces adresses. Sans cette précaution, n'importe qui pourrait envoyer un faux en-tête pour se faire passer pour le serveur Mailpit.
