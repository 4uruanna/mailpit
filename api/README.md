# API webhook Mailpit

Petite API **PHP 8.5 / Slim 4 / MySQL 8.4** pour un hébergement **OVH Perso**. Elle reçoit le webhook que Mailpit envoie à chaque nouveau mail, et enregistre le message en base.

```
Mailpit (serveur Debian) ── POST JSON ──► https://api.amnesic.dev/hook/mailpit ──► MySQL (table mailpit_messages)
                                            │
                                            └─ refusé (403) si l'IP n'est pas dans webhook.allowed_ips
```

> ℹ️ Mailpit n'envoie que le **résumé** du message : expéditeur, destinataires, sujet, date, taille, nombre de pièces jointes, tags et les 250 premiers caractères du texte (`Snippet`).
> Pour recevoir aussi le **corps** (texte et HTML), et si besoin le mail brut `.eml` avec ses pièces jointes, faites passer le webhook par le [relais](../relay/README.md), installé sur le serveur Mailpit ([Partie 7 du tutoriel](../docs/mailpit/07-relay.md)). L'API accepte les deux : le webhook direct de Mailpit et le relais.

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
│   ├── schema.sql              # table MySQL (nouvelle installation)
│   └── upgrade-001-corps.sql   # ajoute les colonnes du corps à une table existante
├── src/                 # code de l'API
├── var/log/             # logs (doit être accessible en écriture)
├── var/messages/        # mails bruts .eml envoyés par le relais (RELAY_INCLUDE_RAW=true)
└── vendor/              # généré par composer install, à envoyer en FTP
```

## Route

| Méthode | URL | Réponse |
|---|---|---|
| `POST` | `/hook/mailpit` | `201 {"status":"created","id":"…"}` : message enregistré<br>`200 {"status":"duplicate","id":"…"}` : déjà reçu (même ID Mailpit)<br>`400` JSON invalide · `413` trop volumineux · `415` pas du JSON · `422` champ invalide<br>`403` IP non autorisée · `500` erreur serveur (base injoignable…) |

Toute autre URL renvoie `404` (ou `403` si l'IP n'est pas autorisée : le filtre IP passe avant tout le reste).

Champs facultatifs ajoutés par le relais au JSON de Mailpit :

| Champ | Enregistré dans |
|---|---|
| `Text` | colonne `text_body` |
| `HTML` | colonne `html_body` |
| `Raw` (mail `.eml` en base64) | fichier `var/messages/<ID>.eml` (dossier `webhook.raw_dir`), dont le nom va dans la colonne `raw_file` |

La colonne `payload` garde le JSON reçu **sans** ces trois champs, pour ne pas stocker le corps deux fois.

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
| `webhook.allowed_ips` | l'IPv4 **et** l'IPv6 du serveur Mailpit (voir ci-dessous) |
| `webhook.token` | facultatif mais recommandé : un secret à mettre dans l'URL du webhook, généré avec `openssl rand -hex 24` (16 caractères minimum) |
| `debug` | `false` en production |

Pour trouver les IP du serveur Mailpit, lancez **sur ce serveur** :

```bash
curl -4 -s https://ifconfig.me; echo
```

```bash
curl -6 -s https://ifconfig.me; echo
```

> ⚠️ **N'oubliez pas l'IPv6.** L'hébergement OVH répond aussi en IPv6. Si le serveur Mailpit en a une, c'est elle qu'il utilisera en priorité pour appeler l'API, et l'IPv4 seule ne suffira pas. Vous pouvez mettre l'adresse exacte ou sa plage `/64`.

### 2. Créer la table

Espace client OVH → **Bases de données** → **…** → **Aller sur phpMyAdmin**, puis onglet **Importer** → fichier `sql/schema.sql`.

> **Table déjà créée avec une version précédente ?** Importez une seule fois `sql/upgrade-001-corps.sql`. Il ajoute les colonnes `text_body`, `html_body` et `raw_file`, nécessaires au relais.

### 3. Envoyer en FTP

Créez par exemple un dossier `api` à la racine FTP de l'hébergement (à côté de `www/`), puis envoyez-y **tout le contenu** de `api/`, **y compris** `vendor/`, `config.php`, `.htaccess`, `public/.htaccess`, `var/.htaccess` et `.ovhconfig`.

> 💡 Les fichiers qui commencent par un point sont souvent masqués dans les clients FTP. Dans FileZilla : menu **Serveur** → **Forcer l'affichage des fichiers cachés**.

Inutile d'envoyer `README.md`, `sql/` et `config.example.php` (ils sont de toute façon bloqués par le `.htaccess`).

Les dossiers `var/log/` et `var/messages/` doivent être accessibles en écriture par PHP (droits `755` ou `705`, ce qui est le cas par défaut chez OVH).

### 4. Brancher le domaine `api.amnesic.dev`

Espace client OVH → **Hébergements** → onglet **Multisite** → **Ajouter un domaine ou sous-domaine** → `api.amnesic.dev`, puis :

- **Dossier racine** : `api/public` (recommandé : seul `public/` est alors visible depuis le web).
  `api` fonctionne aussi : le `.htaccess` racine redirige vers `public/` et bloque le reste.
- Cochez **SSL** et attendez que le certificat soit généré. Mailpit appellera l'API en HTTPS.

**Version de PHP** : le fichier `.ovhconfig` demande PHP 8.5. OVH le lit à la racine de l'hébergement ou à la racine du dossier du multisite. Si vous choisissez `api/public` comme dossier racine, **déplacez `.ovhconfig` dans `public/`**. Si OVH ne propose pas encore la 8.5, mettez `8.4` (le minimum de l'API). Vérifiez la version active dans l'onglet **Informations générales** de l'hébergement.

### 5. Configurer Mailpit

**Avec le relais** (corps des mails) : suivez la [Partie 7 du tuto](../docs/mailpit/07-relay.md). L'URL ci-dessous va alors dans `RELAY_TARGET_URL`, et Mailpit appelle le relais.

**Sans relais** (résumé seulement) : le détail est dans la [Partie 6 du tuto](../docs/mailpit/06-webhook.md). En résumé, sur le serveur Debian, ajoutez à `/etc/mailpit/mailpit.env` :

```ini
# Webhook appelé à chaque nouveau mail.
# Sans jeton (webhook.token = null) : https://api.amnesic.dev/hook/mailpit
MP_WEBHOOK_URL=https://api.amnesic.dev/hook/mailpit?token=LE_JETON_DE_CONFIG_PHP

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

**Depuis le serveur Mailpit** (le seul autorisé), un appel manuel :

```bash
curl -i -X POST 'https://api.amnesic.dev/hook/mailpit?token=LE_JETON_DE_CONFIG_PHP' \
  -H 'Content-Type: application/json' \
  -d '{"ID":"test-manuel-1","Created":"2026-01-01T12:00:00Z","Subject":"Test manuel","From":{"Name":"","Address":"moi@exemple.fr"},"To":[]}'
```

Réponse attendue : `HTTP/2 201` puis `{"status":"created","id":"test-manuel-1"}`. Supprimez ensuite cette ligne de test dans phpMyAdmin.

**De bout en bout** : envoyez un mail comme en [Partie 1, étape 5](../docs/mailpit/01-podman.md#étape-5--démarrer-le-service) :

```bash
swaks --server 127.0.0.1 --port 25 --to essai@test.mondomaine.fr
```

Le message doit apparaître dans la table `mailpit_messages`.

**Depuis votre ordinateur**, le même `curl` doit renvoyer `403` : le filtre IP fonctionne. Depuis le serveur Mailpit, sans le `?token=…` ou avec un mauvais jeton, il doit aussi renvoyer `403`.

**Fichiers protégés** : `https://api.amnesic.dev/config.php`, `/vendor/autoload.php`, `/.ovhconfig`… doivent tous renvoyer `403` ou `404`.

---

## Dépannage

| Symptôme | Cause probable / solution |
|---|---|
| `403` depuis le serveur Mailpit | L'IP vue par l'API n'est pas dans `allowed_ips`. Elle est écrite dans `var/log/api.log` (`[ip-filter] requête refusée depuis « … »`) : ajoutez-la. C'est souvent l'IPv6 |
| `403` alors que l'IP est bien autorisée | Jeton absent ou différent de `webhook.token` (`[webhook] jeton absent ou invalide` dans `var/log/api.log`). Vérifiez `MP_WEBHOOK_URL` |
| `500` avec `{"error":"Erreur de configuration du serveur"}` | `vendor/` ou `config.php` absent, ou `config.php` invalide (détail dans `var/log/api.log`) |
| `500` avec `Slim Application Error` | Souvent la base : identifiants faux ou table absente. Détail dans `var/log/api.log` |
| `404` sur `/hook/mailpit` | `.htaccess` non envoyés (fichiers cachés), ou mauvais dossier racine du multisite. Si l'API est dans un sous-dossier de l'URL, renseignez `base_path` |
| Certains mails manquent en base | `MP_WEBHOOK_LIMIT` n'est pas à `0` (voir l'étape 5) |
| `413` pour les gros mails envoyés par le relais | Augmentez `webhook.max_body_size` (16 Mo par défaut), ou baissez `RELAY_MAX_RAW_SIZE` côté relais |
| `text_body` toujours vide | Le webhook ne passe pas par le relais (`MP_WEBHOOK_URL` pointe directement sur l'API), ou `sql/upgrade-001-corps.sql` n'a pas été importé (erreur `Unknown column` dans le log) |
| Page blanche / erreur PHP | Version de PHP trop ancienne : vérifiez `.ovhconfig` (8.4 minimum) |

Le fichier `var/log/api.log` n'est pas vidé automatiquement : supprimez-le de temps en temps par FTP.

> ℹ️ Le jeton fait partie de l'URL : il apparaît donc dans les logs d'accès de l'hébergement (visibles uniquement depuis votre espace client OVH). Changez-le si vous partagez ces logs.

## Requêtes utiles

```sql
-- Derniers mails reçus
SELECT created_at, from_address, subject FROM mailpit_messages ORDER BY created_at DESC LIMIT 20;

-- Mails envoyés à une adresse donnée
SELECT created_at, subject FROM mailpit_messages
WHERE JSON_CONTAINS(to_addresses, JSON_OBJECT('address', 'essai@test.mondomaine.fr'))
ORDER BY created_at DESC;
```

## Derrière un proxy ou un CDN

Sur un hébergement OVH sans option CDN, l'IP du client est dans `REMOTE_ADDR` : laissez `proxy.trusted_proxies` vide. Si vous activez un CDN devant l'API, listez ses adresses dans `proxy.trusted_proxies`. L'IP réelle sera alors lue dans `X-Forwarded-For`, mais **uniquement** pour les requêtes qui viennent de ces adresses. Sans cette précaution, n'importe qui pourrait envoyer un faux en-tête pour se faire passer pour le serveur Mailpit.
