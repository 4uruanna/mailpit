# API webhook Mailpit

Petite API **PHP 8.5 / Slim 4 / MySQL 8.4** pour un hébergement **OVH Perso**. Elle reçoit chaque mail arrivé dans Mailpit et l'enregistre : le message en base MySQL, les pièces jointes en fichiers.

```
relais (serveur Mailpit) ── 1. POST /v1/messages                     mail en JSON ──► table mailpit_messages
        Authorization:  ◄── réponse : pièces attendues ("missing")
        Bearer <jeton>  ── 2. POST /v1/messages/{id}/part/{partId}   une requête par pièce ──► var/messages/{id}/…
                        ◄── réponse : "complete": true quand tout est enregistré  + table mailpit_parts

GET /v1/messages ──► liste des mails enregistrés (même jeton, même filtre IP)
```

Deux protections, toutes deux obligatoires : l'**IP** de l'appelant doit être dans `webhook.allowed_ips` (sinon `403`), et le **jeton** `webhook.token` doit être présent (sinon `401`). Ce jeton est configuré des deux côtés : dans `config.php` ici, et dans `RELAY_API_TOKEN` côté relais.

Le mail complet est envoyé par le [relais](../relay/README.md), installé sur le serveur Mailpit ([Partie 7 du tutoriel](../docs/mailpit/07-relay.md)) : en-têtes, texte, HTML, pièces jointes et images intégrées. Le relais ne garde rien de son côté : il ne considère un mail comme livré que lorsque l'API répond `"complete": true`.

> ℹ️ L'API accepte aussi le webhook **direct** de Mailpit (sans relais), qui n'envoie qu'un **résumé** : expéditeur, destinataires, sujet, date, taille, nombre de pièces jointes, tags et les 250 premiers caractères du texte (`Snippet`). Voir l'[étape 5](#5-configurer-mailpit).

## Arborescence

```
api/
├── .htaccess            # redirige tout vers public/ et bloque les fichiers sensibles
├── .ovhconfig           # version de PHP sur l'hébergement OVH
├── config.example.php   # modèle de configuration → à copier en config.php
├── composer.json / composer.lock
├── public/
│   ├── .htaccess        # contrôleur frontal Slim
│   └── index.php
├── sql/
│   └── schema.sql       # tables MySQL, à importer une fois
├── src/                 # code de l'API
├── var/log/             # logs (doit être accessible en écriture)
├── var/messages/        # pièces jointes et images intégrées, un dossier par mail (accessible en écriture)
└── vendor/              # généré par composer install, à envoyer en FTP
```

## Routes

Toutes exigent le jeton : en-tête `Authorization: Bearer <jeton>` (relais), ou `?token=<jeton>` dans l'URL (webhook direct de Mailpit, qui ne sait pas ajouter d'en-tête).

| Méthode | URL | Réponse |
|---|---|---|
| `POST` | `/v1/messages` | Corps : le mail en JSON (voir ci-dessous).<br>`201 {"status":"created", "id":"…", "complete":false, "missing":["2","1.3"], "too_large":["4"]}` : message enregistré<br>`200 {"status":"updated"\|"duplicate", …}` : déjà reçu (même ID Mailpit), complété si besoin<br>`400` JSON invalide · `413` trop volumineux · `415` pas du JSON · `422` champ invalide |
| `POST` | `/v1/messages/{id}/part/{partId}` | Corps : le contenu brut de la pièce (`Content-Type: application/octet-stream`), avec l'en-tête `X-Content-SHA256` (empreinte SHA-256 en hexadécimal).<br>`201 {"status":"stored", "complete":true, "missing":[], …}` : pièce enregistrée<br>`200 {"status":"already-stored", …}` : déjà reçue<br>`404` message ou pièce inconnus · `409` pièce `too_large`, ou déjà reçue avec un autre contenu · `413` au-delà de la limite · `415` mauvais Content-Type · `422` empreinte absente ou différente |
| `GET` | `/v1/messages` | Liste des mails enregistrés, du plus récent au plus ancien (voir [Lister les mails](#lister-les-mails)).<br>`200 {"total":…, "count":…, "limit":1000, "offset":0, "messages":[…]}`<br>`400` `limit` ou `offset` invalide |

Pour toutes : `401` jeton absent ou faux · `403` IP non autorisée · `500` erreur serveur (base injoignable…). Toute autre URL renvoie `404` (ou `403` si l'IP n'est pas autorisée : le filtre IP passe avant tout le reste).

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

`composer.json` fixe la plateforme sur PHP 8.5 : les dépendances installées sont celles qui conviennent à l'hébergement, même si votre PHP local est différent.

Éditez `config.php` :

| Clé | Valeur |
|---|---|
| `db.host`, `db.name`, `db.user`, `db.password` | Espace client OVH → **Hébergements** → votre hébergement → onglet **Bases de données** |
| `webhook.allowed_ips` | l'IPv4 du serveur Mailpit, et son IPv6 seulement si elle est stable (voir ci-dessous) |
| `webhook.token` | **obligatoire** : le secret partagé avec le relais, généré avec `openssl rand -hex 32` (32 caractères minimum). La même valeur va dans `RELAY_API_TOKEN` côté relais |
| `webhook.max_attachment_size` | taille maximale d'une pièce jointe, 5 Mo par défaut |
| `webhook.max_messages` | nombre de mails gardés, 1000 par défaut (comme `MP_MAX_MESSAGES` côté Mailpit). `0` = sans limite |
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

Espace client OVH → **Bases de données** → **…** → **Aller sur phpMyAdmin**. Sélectionnez la base dans la colonne de gauche, puis onglet **Importer** → fichier `sql/schema.sql`. Il crée les tables `mailpit_messages` et `mailpit_parts`.

### 3. Envoyer en FTP

Créez par exemple un dossier `api` à la racine FTP de l'hébergement (à côté de `www/`), puis envoyez-y **tout le contenu** de `api/`, **y compris** `vendor/`, `config.php`, `.htaccess`, `public/.htaccess`, `var/.htaccess` et `.ovhconfig`.

> 💡 Les fichiers qui commencent par un point sont souvent masqués dans les clients FTP. Dans FileZilla : menu **Serveur** → **Forcer l'affichage des fichiers cachés**.

Inutile d'envoyer `README.md`, `sql/` et `config.example.php` (ils sont de toute façon bloqués par le `.htaccess`).

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

**Depuis votre ordinateur**, le même `curl` doit renvoyer `403` : le filtre IP fonctionne. Depuis le serveur Mailpit, sans l'en-tête `Authorization` ou avec un mauvais jeton, il doit renvoyer `401`.

**Fichiers protégés** : `https://api.amnesic.dev/config.php`, `/vendor/autoload.php`, `/.ovhconfig`… doivent tous renvoyer `403` ou `404`.

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

Le fichier `var/log/api.log` n'est pas vidé automatiquement : supprimez-le de temps en temps par FTP.

> ℹ️ Avec le relais, le jeton voyage dans un en-tête : il n'apparaît pas dans les logs d'accès de l'hébergement. Avec le webhook direct (`?token=…`), il fait partie de l'URL et apparaît dans ces logs (visibles uniquement depuis votre espace client OVH) : changez-le si vous les partagez.

**Changer le jeton** : générez-en un nouveau (`openssl rand -hex 32`), mettez-le dans `config.php` (envoi FTP) **et** dans `RELAY_API_TOKEN` (`/etc/mailpit/relay.env`, puis `sudo systemctl restart mailpit-relay`). Entre les deux, le relais reçoit des `401` et réessaie : aucun mail n'est perdu.

## Requêtes utiles

```sql
-- Derniers mails reçus
SELECT created_at, from_address, subject FROM mailpit_messages ORDER BY created_at DESC LIMIT 20;

-- Mails envoyés à une adresse donnée
SELECT created_at, subject FROM mailpit_messages
WHERE JSON_CONTAINS(to_addresses, JSON_OBJECT('address', 'essai@test.mondomaine.fr'))
ORDER BY created_at DESC;

-- Pièces jointes d'un mail, avec le fichier correspondant dans var/messages/
SELECT p.file_name, p.content_type, p.size, p.status, p.storage_path
FROM mailpit_parts p JOIN mailpit_messages m ON m.id = p.message_id
WHERE m.mailpit_id = 'Wdyx4jVvBWELmoNePrh8Ff';

-- Mails incomplets (pièces encore attendues)
SELECT mailpit_id, received_at, subject FROM mailpit_messages WHERE completed_at IS NULL;

-- Valeur d'un en-tête
SELECT subject, headers->>'$."X-Mailer"[0]' AS mailer FROM mailpit_messages ORDER BY id DESC LIMIT 10;
```

## Lister les mails

`GET /v1/messages` renvoie les mails enregistrés, du plus récent au plus ancien (date de réception par Mailpit), avec la description de leurs pièces jointes. Le corps (texte, HTML) et les en-têtes n'y sont pas, pour garder la réponse légère.

Comme les autres routes, elle exige le jeton et n'est accessible que depuis les IP de `webhook.allowed_ips`. Pour l'appeler depuis une autre machine, ajoutez son IP à cette liste.

| Paramètre | Défaut | Rôle |
|---|---|---|
| `limit` | `1000` | nombre de mails renvoyés (1 à 1000) |
| `offset` | `0` | nombre de mails à sauter, pour paginer |

Avec la rétention par défaut (1000 mails), un seul appel renvoie **tous** les mails.

```bash
curl -4 -s 'https://api.amnesic.dev/v1/messages?limit=50&offset=0' -H 'Authorization: Bearer LE_JETON_DE_CONFIG_PHP'
```

```json
{
  "total": 2,
  "count": 2,
  "limit": 50,
  "offset": 0,
  "messages": [
    {
      "id": "2qGgGUqCw5N3mAOHzyFGoJ",
      "message_id": "2CJGY3JTeKc0YxSLai06kR@mailpit",
      "from": { "name": "Alice", "address": "alice@exemple.fr" },
      "to": [ { "name": "", "address": "essai@test.mondomaine.fr" } ],
      "cc": [], "bcc": [], "reply_to": [],
      "subject": "Devis",
      "snippet": "Bonjour, voici le devis…",
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
      ]
    }
  ]
}
```

| Champ | Contenu |
|---|---|
| `id` | ID du mail dans Mailpit (colonne `mailpit_id`), utilisé aussi pour son dossier `var/messages/<id>/` |
| `date` | en-tête `Date` du mail · `created_at` : réception par Mailpit · `received_at` : réception par l'API (UTC, ISO 8601) |
| `complete` | `true` quand toutes les pièces attendues sont enregistrées |
| `parts[].status` | `stored` (fichier `var/messages/<id>/part-<part_id>`), `pending` (pas encore reçue) ou `too_large` (au-delà de la limite, non enregistrée) |
| `label`, `username` | en-tête `Mailpit-Label` (`RELAY_LABEL`) et utilisateur SMTP authentifié, ou `null` |

## Rétention : les 1000 derniers mails

À chaque **nouveau** mail, l'API supprime les plus anciens pour n'en garder que `webhook.max_messages` (1000 par défaut) : leur ligne dans `mailpit_messages`, leurs pièces dans `mailpit_parts`, et leur dossier `var/messages/<mailpit_id>/`. « Les plus anciens » s'entend dans l'ordre d'arrivée dans l'API. Une grosse purge (par exemple après avoir baissé la limite) se fait par paquets de 200 mails, au fil des mails suivants.

Gardez la même valeur que `MP_MAX_MESSAGES` côté Mailpit ([Partie 1, étape 3](../docs/mailpit/01-podman.md#étape-3--créer-le-fichier-de-configuration-de-mailpit)) : Mailpit et l'API conservent alors les mêmes mails.

Pour supprimer un mail à la main, supprimez sa ligne dans `mailpit_messages` (ses lignes de `mailpit_parts` partent avec elle) et son dossier `var/messages/<mailpit_id>/` par FTP.

## Derrière un proxy ou un CDN

Sur un hébergement OVH sans option CDN, l'IP du client est dans `REMOTE_ADDR` : laissez `proxy.trusted_proxies` vide. Si vous activez un CDN devant l'API, listez ses adresses dans `proxy.trusted_proxies`. L'IP réelle sera alors lue dans `X-Forwarded-For`, mais **uniquement** pour les requêtes qui viennent de ces adresses. Sans cette précaution, n'importe qui pourrait envoyer un faux en-tête pour se faire passer pour le serveur Mailpit.
