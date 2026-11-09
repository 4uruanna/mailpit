# Partie 7 — Relayer le contenu complet des mails vers une API

> Remplacez les valeurs d'exemple (`mondomaine.fr`, `admin:MotDePasseSolide`…) comme indiqué dans [Avant de commencer](../mailpit.md#avant-de-commencer). `api.mondomaine.fr` désigne ici l'API du dossier [`api/`](../../api/README.md), déployée sur un hébergement web.

Le webhook de la [Partie 6](06-webhook.md) n'envoie qu'un **résumé** du mail. Le contenu complet n'est lisible que par l'API de Mailpit, qui n'écoute que sur le serveur (`127.0.0.1:8025`). Une API hébergée ailleurs ne peut donc pas le récupérer.

Le **relais** (dossier [`relay/`](../../relay/README.md) du dépôt) comble ce manque. C'est un petit service PHP qui tourne dans Podman, à côté de Mailpit :

```
                    serveur Debian (réseau Podman privé « mailpit »)
┌───────────────────────────────────────────────────────────────────────┐
│  Mailpit ── 1. webhook (« nouveau mail ») ──► mailpit-relay           │
│     ▲                                             │  n'enregistre     │
│     ├──── 2. lit le mail complet et ses pièces ───┤  rien             │
│     └──── 4. tag « relayed » quand tout est reçu ─┤                   │
└───────────────────────────────────────────────────┼───────────────────┘
                                                    │ 3. le mail (JSON), puis chaque pièce jointe
                                                    │    Authorization: Bearer <jeton>
                                                    ▼
                                     https://api.mondomaine.fr/v1/messages
```

Ce que le relais apporte :

- **Le mail complet** : en-têtes, texte, HTML, pièces jointes et images intégrées (5 Mo maximum par pièce, réglable côté API). Tout est enregistré par l'API : le relais ne garde rien.
- **Aucun mail perdu.** Le relais ne pose le tag `relayed` sur un mail dans Mailpit qu'une fois que l'API a confirmé avoir **tout** enregistré. Tant que ce n'est pas le cas, il réessaie (30 s, 1 min, 2 min… puis toutes les heures, sans limite), y compris après un redémarrage. C'est Mailpit qui sert de file d'attente. Mailpit seul, lui, n'essaie qu'une fois.
- **Un mail à la fois.** Le relais termine un mail (message, pièces jointes une par une, tag) avant de passer au suivant : il n'envoie jamais deux requêtes en même temps à l'API.
- **Les 1000 derniers mails, des deux côtés.** Mailpit (`MP_MAX_MESSAGES=1000`, [Partie 1](01-podman.md#étape-3--créer-le-fichier-de-configuration-de-mailpit)) et l'API (`webhook.max_messages`) suppriment les plus anciens au-delà de 1000.
- **Un jeton des deux côtés.** Le relais s'authentifie auprès de l'API avec un jeton configuré dans `relay.env` **et** dans le `config.php` de l'API, envoyé dans un en-tête (jamais dans l'URL).
- **Rien n'est exposé.** L'interface de Mailpit reste sur `127.0.0.1`, et le relais n'ouvre aucun port sur le serveur.

**Prérequis :**

- Parties 1 à 3 terminées : Mailpit reçoit des mails.
- L'API déployée sur l'hébergement, avec le schéma SQL à jour (voir son [README](../../api/README.md)). L'IP du serveur Mailpit doit être dans `allowed_ips` : le relais tourne sur ce serveur, il appelle donc l'API depuis la même IP.
- Le **jeton** de l'API (`webhook.token` dans son `config.php`). Si vous ne l'avez pas encore, générez-le avec `openssl rand -hex 32` et mettez-le dans `config.php` (32 caractères minimum).
- **Debian 13** (Quadlet). Pour Debian 12, voir l'[annexe](#annexe--debian-12).

## Étape 1 — Installer les outils

```bash
sudo apt install -y git aardvark-dns
```

`aardvark-dns` permet aux conteneurs d'un même réseau Podman de se joindre **par leur nom** (`mailpit`, `mailpit-relay`). Il est souvent déjà installé avec Podman.

## Étape 2 — Récupérer le code du relais

```bash
sudo git clone -b dev https://github.com/4uruanna/mailpit.git /opt/mailpit-src
```

> ℹ️ `-b dev` : le code du relais est pour l'instant sur la branche `dev`. La branche par défaut (`main`) ne le contient pas encore : sans `-b dev`, le dossier `relay/` serait absent et l'étape 3 échouerait. Une fois `dev` fusionnée dans `main`, l'option devient inutile.

> 💡 Si le dépôt est privé, copiez simplement le dossier depuis votre ordinateur :
> `scp -r relay utilisateur@IP_DU_SERVEUR:/tmp/relay`, puis sur le serveur :
> `sudo mkdir -p /opt/mailpit-src && sudo mv /tmp/relay /opt/mailpit-src/relay`.

## Étape 3 — Construire l'image

```bash
sudo podman build --pull -t localhost/mailpit-relay:latest /opt/mailpit-src/relay
```

Podman télécharge l'image officielle PHP 8.5 (Alpine), ajoute le code du relais, et enregistre le résultat sous le nom `localhost/mailpit-relay`. Comptez une à deux minutes. Vérifiez :

```bash
sudo podman images | grep mailpit-relay
```

## Étape 4 — Créer un réseau privé pour Mailpit et le relais

Mailpit et le relais doivent se parler. Sur le réseau Podman par défaut, les conteneurs ne se trouvent pas par leur nom. On crée donc un réseau dédié.

```bash
sudo nano /etc/containers/systemd/mailpit.network
```

Contenu :

```ini
[Unit]
Description=Réseau privé de Mailpit et de son relais

[Network]
NetworkName=mailpit
```

Ajoutez ensuite ce réseau à Mailpit. Dans `/etc/containers/systemd/mailpit.container` ([Partie 1, étape 4](01-podman.md#étape-4--créer-le-service-systemd-quadlet)), ajoutez cette ligne dans la section `[Container]`, par exemple sous `Volume=mailpit-data:/data` :

```ini
Network=mailpit.network
```

Les `PublishPort` ne changent pas : le port 25 reste ouvert sur Internet, et l'interface reste sur `127.0.0.1:8025`.

## Étape 5 — Configurer le relais

```bash
sudo nano /etc/mailpit/relay.env
```

Contenu (modèle complet : [`relay/relay.env.example`](../../relay/relay.env.example)) :

```ini
# Adresse de base de l'API (sans « /v1/messages » ni « ?token=… »)
RELAY_API_URL=https://api.mondomaine.fr

# Le jeton de l'API : EXACTEMENT la valeur de webhook.token dans son config.php
RELAY_API_TOKEN=LE_JETON_DE_CONFIG_PHP

# Mailpit, vu depuis le relais : le nom du conteneur sur le réseau « mailpit »
RELAY_MAILPIT_URL=http://mailpit:8025

# Les identifiants de MP_UI_AUTH (fichier mailpit.env)
RELAY_MAILPIT_AUTH=admin:MotDePasseSolide

# Facultatif : ne relayer que les mails reçus à partir de cette date (voir l'encadré)
#RELAY_QUERY=after:2026-11-08
```

Protégez le fichier, car il contient des secrets :

```bash
sudo chmod 600 /etc/mailpit/relay.env
```

> ⚠️ **Au premier démarrage, le relais envoie à l'API tous les mails déjà présents dans Mailpit.** C'est voulu : il relaie tout mail qui n'a pas encore le tag `relayed`. Pour ne relayer que les nouveaux, décommentez `RELAY_QUERY` avec la date du jour.

> ℹ️ L'API enregistre les pièces jointes en fichiers dans son dossier `var/messages/`. Pensez à la taille : l'espace d'un hébergement mutualisé est limité.

## Étape 6 — Créer le service du relais

```bash
sudo nano /etc/containers/systemd/mailpit-relay.container
```

Contenu :

```ini
[Unit]
Description=Relais Mailpit -> API (contenu complet des mails)
After=mailpit.service

[Container]
ContainerName=mailpit-relay
Image=localhost/mailpit-relay:latest
EnvironmentFile=/etc/mailpit/relay.env
# Le relais n'enregistre rien : conteneur en lecture seule, sans volume
ReadOnly=true
Network=mailpit.network
# Pas de PublishPort : seul Mailpit, sur le réseau privé, peut joindre le relais

[Service]
Restart=always

[Install]
WantedBy=multi-user.target
```

Vérifiez que Quadlet comprend les trois fichiers :

```bash
sudo /usr/lib/systemd/system-generators/podman-system-generator --dryrun 2>&1 | grep -E '^---|rror'
```

Vous devez voir `---mailpit-network.service---`, `---mailpit.service---` et `---mailpit-relay.service---`, sans erreur.

## Étape 7 — Envoyer le webhook de Mailpit au relais

Dans `/etc/mailpit/mailpit.env`, remplacez (ou ajoutez) les lignes du webhook :

```ini
# Le relais, joint par son nom sur le réseau « mailpit »
MP_WEBHOOK_URL=http://mailpit-relay:8080/hook

# Un appel pour CHAQUE mail (voir Partie 6)
MP_WEBHOOK_LIMIT=0
```

Avec `MP_WEBHOOK_LIMIT=0`, chaque mail réveille le relais aussitôt. Un webhook manqué n'est pas grave : le relais cherche de toute façon les nouveaux mails toutes les 30 secondes (`RELAY_POLL_INTERVAL`).

## Étape 8 — Démarrer

```bash
sudo systemctl daemon-reload
sudo systemctl restart mailpit          # recrée le conteneur sur le réseau « mailpit »
sudo systemctl start mailpit-relay
sudo systemctl status mailpit mailpit-relay
```

Les logs du relais doivent afficher une ligne `démarrage : écoute sur 0.0.0.0:8080…` qui résume la configuration (seuls les 4 premiers caractères du jeton y figurent) :

```bash
sudo journalctl -u mailpit-relay -n 20
```

Vérifiez que le réseau a bien la résolution de noms :

```bash
sudo podman network inspect mailpit | grep dns_enabled     # attendu : "dns_enabled": true
```

## Étape 9 — Tester

Dans un premier terminal, suivez les logs du relais :

```bash
sudo journalctl -u mailpit-relay -f
```

Dans un second, envoyez un mail de test avec une pièce jointe :

```bash
swaks --server 127.0.0.1 --port 25 --to essai@test.mondomaine.fr --attach @/etc/os-release
```

En une ou deux secondes, vous devez voir :

```
[relay] INFO  mail Wdyx4jVvBWELmoNePrh8Ff enregistré par l'API (test Sun, 08 Nov 2026 …), 1 pièce(s) envoyée(s), essai 1
```

Côté API, le mail apparaît dans la table `mailpit_messages` avec ses colonnes `text_body`, `headers` et `completed_at` remplies, et la pièce jointe dans la table `mailpit_parts` (état `stored`), avec son fichier dans `var/messages/<ID>/`. Dans l'interface de Mailpit, le mail porte le tag **relayed** et reste **non lu**.

---

## Au quotidien

**Mails en attente et mails refusés**, lus dans Mailpit :

```bash
sudo podman exec mailpit-relay php /app/bin/status.php
```

Dans l'interface de Mailpit, les mails relayés portent le tag `relayed`, et ceux que l'API a refusés définitivement le tag `relay-failed`. La recherche `!tag:relayed` y affiche ceux qui ne sont pas encore arrivés dans l'API.

**Relancer les mails refusés** (tag `relay-failed`), une fois la cause corrigée. La cause est dans les logs du relais (`refusé par l'API`) et dans `var/log/api.log` :

```bash
sudo podman exec mailpit-relay php /app/bin/retry-failed.php
```

Les erreurs passagères (API en panne, jeton ou IP refusés…) n'ont pas besoin de cette commande : le relais réessaie tout seul, sans limite de durée.

**Renvoyer un mail précis** : retirez-lui le tag `relayed` dans l'interface de Mailpit. Le relais le renvoie, et l'API le reconnaît comme un doublon.

**Mettre à jour le relais** (nouveau code, ou nouvelle version de PHP) :

```bash
sudo git -C /opt/mailpit-src pull
sudo podman build --pull -t localhost/mailpit-relay:latest /opt/mailpit-src/relay
sudo systemctl restart mailpit-relay
```

> ℹ️ `podman auto-update` ([Partie 5](05-maintenance.md#mailpit-image-du-conteneur)) ne s'occupe pas du relais : son image est construite sur le serveur. Refaites la commande `podman build` ci-dessus de temps en temps pour récupérer les correctifs de PHP.

**Changer le mot de passe de Mailpit** (`MP_UI_AUTH`) : reportez-le dans `RELAY_MAILPIT_AUTH`, puis redémarrez les deux services :

```bash
sudo systemctl restart mailpit mailpit-relay
```

**Changer le jeton** : générez-en un nouveau (`openssl rand -hex 32`), mettez-le dans le `config.php` de l'API, puis dans `RELAY_API_TOKEN`, et lancez `sudo systemctl restart mailpit-relay`. Entre les deux, le relais reçoit des `401` et réessaie : aucun mail n'est perdu.

Un arrêt du relais ne fait perdre **aucun** mail, même ceux arrivés pendant l'arrêt : au redémarrage, il relaie tous les mails qui n'ont pas encore le tag `relayed`. La seule perte possible est un mail **supprimé de Mailpit** avant d'avoir été relayé (à la main, ou par `MP_MAX_MESSAGES` / `MP_MAX_AGE`). Avec `MP_MAX_MESSAGES=1000`, cela n'arrive que si plus de 1000 mails arrivent pendant une panne de l'API.

**Rétention** : gardez la même valeur dans `MP_MAX_MESSAGES` (`/etc/mailpit/mailpit.env`) et dans `webhook.max_messages` (`config.php` de l'API), 1000 par défaut. Les deux côtés conservent alors les mêmes mails.

---

## Dépannage relais

| Symptôme | Cause probable / solution |
|---|---|
| Logs du relais : `configuration invalide : …` | Valeur manquante ou mal écrite dans `/etc/mailpit/relay.env` (le message dit laquelle) |
| `systemctl start mailpit-relay` : image introuvable | L'étape 3 n'a pas été faite, ou le nom ne correspond pas à `Image=` |
| Logs de **Mailpit** : `no such host` pour `mailpit-relay` | Les deux conteneurs ne sont pas sur le réseau `mailpit` (ligne `Network=` oubliée, Mailpit pas redémarré), ou `aardvark-dns` absent (`dns_enabled: false`) |
| Logs de **Mailpit** : `connection refused` | Le relais est arrêté : `sudo systemctl status mailpit-relay` |
| Relais : `Mailpit /api/v1/message/… : HTTP 401` | `RELAY_MAILPIT_AUTH` ne correspond pas à `MP_UI_AUTH` |
| Relais : `Could not resolve host: mailpit` | Mailpit n'est pas sur le réseau `mailpit` (étape 4), ou son conteneur ne s'appelle pas `mailpit` |
| Relais : `un autre relais tourne déjà dans ce conteneur` | Vous avez lancé `bin/relay.php` à la main dans le conteneur : inutile, le relais tourne déjà. Les scripts `status.php` et `retry-failed.php`, eux, peuvent être lancés à tout moment |
| Relais : `configuration invalide : RELAY_API_TOKEN manquant ou trop court` | Mettez dans `RELAY_API_TOKEN` la valeur de `webhook.token` (32 caractères minimum) |
| Relais : `RELAY_TARGET_URL n'existe plus` | Ancienne configuration : remplacez-la par `RELAY_API_URL=https://api.mondomaine.fr` |
| Relais : `RELAY_INCLUDE_RAW n'existe plus` | Les `.eml` ne sont plus gérés : retirez la ligne de `relay.env` (simple avertissement) |
| Relais : `HTTP 404` ou `HTTP 405` | L'API n'est pas à jour (elle attend encore `/hook/mailpit`), ou `RELAY_API_URL` contient un chemin en trop (`/v1/messages`, `/hook/mailpit`) |
| Relais : `HTTP 401` | Jeton différent entre `RELAY_API_TOKEN` et `webhook.token` de l'API, ou `.htaccess` de l'API pas à jour (l'en-tête `Authorization` n'arrive pas à PHP). Le relais réessaie : corrigez, il n'y a rien à relancer |
| Relais : `HTTP 403` | IP du serveur absente de `allowed_ips` (pensez à l'IPv6). Le relais réessaie : corrigez l'API, il n'y a rien à relancer |
| Relais : `HTTP 500` | Souvent la base : `sql/schema.sql` pas importé, ou identifiants MySQL faux dans `config.php`. Détail dans `var/log/api.log` |
| Relais : `refusé par l'API (… HTTP 413 …)` | Le JSON du mail dépasse `max_body_size` de l'API : augmentez-le dans son `config.php`, puis lancez `retry-failed.php` |
| Relais : `au-delà de la limite de l'API, seule sa description est enregistrée` | Pièce jointe de plus de 5 Mo (`max_attachment_size`). Normal : le mail est livré sans ce contenu |
| Relais : `API (message) injoignable : …` | Hébergement en panne, DNS ou certificat : le relais réessaie tout seul |
| Relais : `supprimé de Mailpit avant d'avoir été relayé` | Mail supprimé avant d'être relayé (à la main, ou par `MP_MAX_MESSAGES` / `MP_MAX_AGE`) : il ne peut plus être envoyé |
| Relais : `Mailpit … : HTTP 400` sur la recherche | Filtre `RELAY_QUERY` mal écrit (syntaxe de recherche de Mailpit) |

> ℹ️ Vous utilisiez aussi `host.containers.internal` avec ufw (dépannage de la [Partie 6](06-webhook.md#dépannage-webhook)) ? Le réseau `mailpit` n'utilise pas le sous-réseau `10.88.0.0/16`. Retrouvez le sien avec `sudo podman network inspect mailpit | grep subnet` et adaptez la règle ufw.

---

## Annexe — Debian 12

Sans Quadlet, créez le réseau et les conteneurs à la main, puis générez les services :

```bash
sudo podman network create mailpit

# Recréer Mailpit sur ce réseau (même commande que l'annexe de la Partie 1, avec --network)
sudo systemctl stop container-mailpit
sudo podman run -d --name mailpit --network mailpit \
  --env-file /etc/mailpit/mailpit.env \
  -v mailpit-data:/data \
  -p 25:1025 -p 127.0.0.1:8025:8025 \
  docker.io/axllent/mailpit:latest

sudo podman run -d --name mailpit-relay --network mailpit \
  --env-file /etc/mailpit/relay.env \
  --read-only \
  localhost/mailpit-relay:latest

cd /etc/systemd/system
sudo podman generate systemd --new --files --name mailpit
sudo podman generate systemd --new --files --name mailpit-relay
sudo podman rm -f mailpit mailpit-relay
sudo systemctl daemon-reload
sudo systemctl enable --now container-mailpit.service container-mailpit-relay.service
```

Si vous aviez ajouté `-v /etc/mailpit/certs:/certs:ro` en [Partie 4](04-tls.md), remettez-le dans la commande `podman run` de Mailpit. Les services s'appellent alors `container-mailpit` et `container-mailpit-relay` : adaptez les commandes `systemctl` et `journalctl` de cette page.

---

[←](06-webhook.md) · [Sommaire](../mailpit.md) · [→](08-tests-relay-api.md)
