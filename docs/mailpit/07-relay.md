# Partie 7 — Relayer le contenu complet des mails vers une API

> Remplacez les valeurs d'exemple (`mondomaine.fr`, `admin:MotDePasseSolide`…) comme indiqué dans [Avant de commencer](../mailpit.md#avant-de-commencer). `api.mondomaine.fr` désigne ici l'API du dossier [`api/`](../../api/README.md), déployée sur un hébergement web.

Le webhook de la [Partie 6](06-webhook.md) n'envoie qu'un **résumé** du mail. Le contenu complet n'est lisible que par l'API de Mailpit, qui n'écoute que sur le serveur (`127.0.0.1:8025`). Une API hébergée ailleurs ne peut donc pas le récupérer. De plus, Mailpit ne sait pas ajouter d'en-tête : l'API du dossier `api/`, qui n'accepte son jeton que dans l'en-tête `Authorization`, refuse son webhook direct.

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
                                                    │    + un ping de santé toutes les 30 s
                                                    │    Authorization: Bearer <jeton>
                                                    ▼
                                     https://api.mondomaine.fr/v1/messages
```

Ce que le relais apporte :

- **Le mail complet** : en-têtes, texte, HTML, pièces jointes et images intégrées (5 Mo maximum par pièce, réglable côté API). Tout est enregistré par l'API : le relais ne garde rien.
- **Aucun mail perdu.** Le relais ne pose le tag `relayed` sur un mail dans Mailpit qu'une fois que l'API a confirmé avoir **tout** enregistré. Tant que ce n'est pas le cas, il réessaie (30 s, 1 min, 2 min… puis toutes les heures, sans limite), y compris après un redémarrage. C'est Mailpit qui sert de file d'attente. Mailpit seul, lui, n'essaie qu'une fois.
- **Un mail à la fois.** Le relais termine un mail (message, pièces jointes une par une, tag) avant de passer au suivant : il n'envoie jamais deux requêtes en même temps à l'API pour les mails. Seul le ping de santé (ci-dessous) part à côté.
- **Un ping de santé toutes les 30 secondes.** Depuis un processus à part, le relais envoie à l'API sa latence et le nombre de mails en attente et refusés. La page **Statut** du Dashboard de l'API montre ainsi si le relais est connecté, et ses coupures sur 24 heures.
- **Les plus anciens d'abord.** Après une panne de l'API, le relais rattrape son retard du plus ancien au plus récent : ce sont les plus anciens que Mailpit supprime en premier. Un mail qui attend son nouvel essai ne bloque pas les suivants.
- **7 jours et 1000 mails au plus, des deux côtés.** Mailpit (`MP_MAX_MESSAGES=1000` et `MP_MAX_AGE=7d`, [Partie 1](01-podman.md#étape-3--créer-le-fichier-de-configuration-de-mailpit)) et l'API (`webhook.max_messages` et `webhook.max_age_days`) suppriment les plus anciens au-delà de 1000, et ceux reçus il y a plus de 7 jours. Côté API, ce nettoyage a lieu après les requêtes du relais (mails et pings de santé), au plus toutes les 15 minutes : aucune tâche planifiée à créer sur l'hébergement.
- **Un jeton des deux côtés.** Le relais s'authentifie auprès de l'API avec un jeton configuré dans `relay.env` **et** dans le `config.php` de l'API, envoyé dans un en-tête (jamais dans l'URL : l'API refuse un `?token=…`, qui finirait dans les logs d'accès de l'hébergement).
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

### Si ufw est actif : laisser sortir les conteneurs

Vérifiez avec `sudo ufw status verbose`. Si la ligne `Default:` contient **`deny (routed)`** (le réglage par défaut d'ufw), tout ce qui **sort** d'un conteneur est bloqué : le trafic d'un conteneur traverse le serveur, il est donc « routé ». Sans règle, la construction de l'image échoue à l'étape 3 (`DNS: transient error`, puis `unable to select packages`), et le relais ne pourrait pas joindre l'API.

```bash
# Conteneurs du réseau par défaut (dont la construction de l'image, étape 3)
sudo ufw route allow from 10.88.0.0/16 comment 'Podman : sortie des conteneurs (reseau par defaut)'

# Conteneurs du réseau « mailpit » (étape 4) : Mailpit et le relais
sudo ufw route allow from 10.89.25.0/24 comment 'Podman : sortie du reseau mailpit'
```

Ces règles laissent les conteneurs sortir et recevoir les réponses. Elles n'ouvrent **aucun** port depuis l'extérieur.

Il faut aussi laisser les conteneurs du réseau `mailpit` interroger son **DNS interne** (`aardvark-dns`, à l'adresse `10.89.25.1`). Cette adresse est celle du serveur lui-même, et non une destination routée : ufw la filtre donc comme du trafic **entrant**, bloqué par `deny (incoming)`. Sans cette règle, le relais ne trouve pas Mailpit par son nom (`Resolving timed out`, et des lignes `UFW BLOCK … DST=10.89.25.1 … DPT=53` dans `journalctl -k`) :

```bash
sudo ufw allow proto udp from 10.89.25.0/24 to 10.89.25.1 port 53 comment 'Podman : DNS interne du reseau mailpit'
sudo ufw allow proto tcp from 10.89.25.0/24 to 10.89.25.1 port 53 comment 'Podman : DNS interne du reseau mailpit'
```

Ces deux règles n'acceptent que les requêtes venues des conteneurs du réseau `mailpit`, et seulement sur le port DNS : rien ne s'ouvre vers Internet ni vers le réseau local.

Pour vérifier depuis un conteneur jetable :

```bash
sudo podman run --rm docker.io/library/alpine:latest sh -c 'nslookup dl-cdn.alpinelinux.org && wget -T 5 -q -O /dev/null http://1.1.1.1 && echo OK'
```

Attendu : des lignes `Address: …`, puis `OK`.

## Étape 2 — Récupérer le code du relais

```bash
sudo git clone https://github.com/4uruanna/mailpit.git /opt/mailpit-src
```

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
# Plage fixe : les règles ufw (étapes 1 et 4) peuvent la viser précisément
Subnet=10.89.25.0/24
```

Ajoutez ensuite ce réseau à Mailpit. Dans `/etc/containers/systemd/mailpit.container` ([Partie 1, étape 4](01-podman.md#étape-4--créer-le-service-systemd-quadlet)), ajoutez cette ligne dans la section `[Container]`, par exemple sous `Volume=mailpit-data:/data` :

```ini
Network=mailpit.network
```

Les `PublishPort` ne changent pas : le port 25 reste ouvert sur Internet, et l'interface reste sur `127.0.0.1:8025`.

> ⚠️ **Avec ufw**, Mailpit change de sous-réseau : il passe de `10.88.0.0/16` à `10.89.25.0/24`. La règle `ufw route` de la [Partie 1, étape 6](01-podman.md#étape-6--ouvrir-le-port-25-dans-le-pare-feu), qui laisse entrer le SMTP, ne s'applique plus. **Sans la règle ci-dessous, plus aucun mail n'arrive depuis Internet** (les tests locaux sur `127.0.0.1` continuent pourtant de passer) :
>
> ```bash
> sudo ufw route allow proto tcp to 10.89.25.0/24 port 1025 comment 'Mailpit SMTP (reseau mailpit)'
> ```
>
> L'ancienne règle `10.88.0.0/16 1025/tcp` ne sert plus. Vous pouvez la supprimer une fois les tests passés : `sudo ufw status numbered`, puis `sudo ufw delete <numéro>`.

## Étape 5 — Configurer le relais

```bash
sudo nano /etc/mailpit/relay.env
```

Contenu (modèle complet : [`relay/relay.env.example`](../../relay/relay.env.example)) :

```ini
# Adresse de base de l'API, en https:// (sans « /v1/messages » ni « ?token=… »)
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

> 💡 Le relais envoie aussi un **ping de santé** à l'API toutes les 30 secondes : c'est lui qui alimente la page **Statut** de son Dashboard. Pour changer l'intervalle (60 secondes au plus : la page compte une coupure dès 90 secondes sans ping), ou le désactiver avec `0` : `RELAY_HEARTBEAT_INTERVAL` (voir le modèle complet). L'API doit avoir la table `mailpit_health_pings` (`sql/schema.sql`, ou la migration `sql/migrations/2026-11-health-pings.sql` pour une base plus ancienne).

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

> ⚠️ `MP_WEBHOOK_URL` pointait directement sur l'API (`https://api.mondomaine.fr/v1/messages?token=…`) ? L'API refuse désormais ce jeton dans l'URL (`401`) : remplacez la ligne comme ci-dessus, puis changez le jeton (voir « Changer le jeton » plus bas), car il figure dans les logs d'accès de l'hébergement.

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

Aucune ligne `ping de santé …` ne doit suivre : le relais n'écrit dans son journal que les échecs du ping (voir [Dépannage](#dépannage-relais)).

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

**Ping de santé** : dans le Dashboard de l'API (`https://api.mondomaine.fr/v1/app/status`, menu **Statut**), la page indique **Connecté**, avec la date du dernier ping (moins de 30 secondes), la latence, et **0** mail en attente. Le graphique se remplit au fil des minutes.

> ⚠️ « Aucun ping reçu » : la table `mailpit_health_pings` manque côté API (migration `sql/migrations/2026-11-health-pings.sql`), ou l'API est une version précédente. Le journal du relais le dit (`ping de santé non enregistré : … HTTP 500` ou `HTTP 404`). Les mails, eux, sont relayés normalement.

---

## Au quotidien

**État de la connexion** : page **Statut** du Dashboard de l'API. Connecté ou non, latence, mails en attente et refusés, disponibilité et coupures sur 24 heures, d'après le ping de santé que le relais envoie toutes les 30 secondes (`RELAY_HEARTBEAT_INTERVAL`, `0` pour le désactiver).

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

Un arrêt du relais ne fait perdre **aucun** mail, même ceux arrivés pendant l'arrêt : au redémarrage, il relaie tous les mails qui n'ont pas encore le tag `relayed`. La seule perte possible est un mail **supprimé de Mailpit** avant d'avoir été relayé (à la main, ou par `MP_MAX_MESSAGES` / `MP_MAX_AGE`). Avec `MP_MAX_MESSAGES=1000` et `MP_MAX_AGE=7d`, cela n'arrive que si plus de 1000 mails arrivent pendant une panne de l'API, ou si elle dure plus de 7 jours.

**Rétention** : gardez la même valeur dans `MP_MAX_MESSAGES` (`/etc/mailpit/mailpit.env`) et dans `webhook.max_messages` (`config.php` de l'API), 1000 par défaut, ainsi que dans `MP_MAX_AGE` et `webhook.max_age_days` (`7d` et `7`). Les deux côtés conservent alors les mêmes mails.

---

## Dépannage relais

| Symptôme | Cause probable / solution |
|---|---|
| Logs du relais : `configuration invalide : …` | Valeur manquante ou mal écrite dans `/etc/mailpit/relay.env` (le message dit laquelle) |
| Build de l'image : `DNS: transient error`, puis `unable to select packages` | ufw bloque la sortie des conteneurs (`deny (routed)`) : voir l'[étape 1](#si-ufw-est-actif--laisser-sortir-les-conteneurs) |
| Relais : `Mailpit /api/v1/search : Resolving timed out` (ou `Connection timeout`) en boucle | ufw bloque le DNS interne de Podman (`UFW BLOCK … DST=10.89.25.1 … DPT=53` dans `sudo journalctl -k`) : règles DNS de l'[étape 1](#si-ufw-est-actif--laisser-sortir-les-conteneurs) |
| Relais : `API (message) injoignable : … Could not resolve host` ou `timed out` dès le premier mail | Même cause : règle `ufw route allow from 10.89.25.0/24` manquante (étape 1) |
| Plus aucun mail n'arrive depuis Internet après l'étape 8 (mais `swaks` vers `127.0.0.1` passe) | Règle ufw du SMTP pas adaptée au réseau `mailpit` : voir l'encadré de l'[étape 4](#étape-4--créer-un-réseau-privé-pour-mailpit-et-le-relais) |
| `systemctl start mailpit-relay` : image introuvable | L'étape 3 n'a pas été faite, ou le nom ne correspond pas à `Image=` |
| Logs de **Mailpit** : `no such host` pour `mailpit-relay` | Les deux conteneurs ne sont pas sur le réseau `mailpit` (ligne `Network=` oubliée, Mailpit pas redémarré), ou `aardvark-dns` absent (`dns_enabled: false`) |
| Logs de **Mailpit** : `connection refused` | Le relais est arrêté : `sudo systemctl status mailpit-relay` |
| Relais : `Mailpit /api/v1/message/… : HTTP 401` | `RELAY_MAILPIT_AUTH` ne correspond pas à `MP_UI_AUTH` |
| Relais : `Could not resolve host: mailpit` | Mailpit n'est pas sur le réseau `mailpit` (étape 4), ou son conteneur ne s'appelle pas `mailpit` |
| Relais : `un autre relais tourne déjà dans ce conteneur` | Vous avez lancé `bin/relay.php` à la main dans le conteneur : inutile, le relais tourne déjà. Les scripts `status.php` et `retry-failed.php`, eux, peuvent être lancés à tout moment |
| Relais : `configuration invalide : RELAY_API_URL doit commencer par https://` | Le relais refuse d'envoyer le jeton et les mails en clair : mettez `https://` dans `RELAY_API_URL`. Si le certificat de l'API n'est pas encore prêt, attendez-le plutôt que de passer en `http://` (l'API refuserait de toute façon, `403`) |
| Relais : `configuration invalide : RELAY_API_TOKEN manquant ou trop court` | Mettez dans `RELAY_API_TOKEN` la valeur de `webhook.token` (32 caractères minimum) |
| Relais : `HTTP 404` ou `HTTP 405` | `RELAY_API_URL` contient un chemin en trop (elle doit s'arrêter au domaine, sans `/v1/messages`), ou mauvais dossier racine du multisite côté OVH |
| Relais : `HTTP 401` | Jeton différent entre `RELAY_API_TOKEN` et `webhook.token` de l'API, ou `.htaccess` de l'API pas à jour (l'en-tête `Authorization` n'arrive pas à PHP). Le relais réessaie : corrigez, il n'y a rien à relancer |
| Relais : `HTTP 403` | IP du serveur absente de `allowed_ips` (pensez à l'IPv6). Le relais réessaie : corrigez l'API, il n'y a rien à relancer |
| Relais : `HTTP 500` | Souvent la base : `sql/schema.sql` pas importé, ou identifiants MySQL faux dans `config.php`. Détail dans `var/log/api.log` |
| Relais : `refusé par l'API (… HTTP 413 …)` | Le JSON du mail dépasse `max_body_size` de l'API : augmentez-le dans son `config.php`, puis lancez `retry-failed.php` |
| Relais : `au-delà de la limite de l'API, seule sa description est enregistrée` | Pièce jointe de plus de 5 Mo (`max_attachment_size`). Normal : le mail est livré sans ce contenu |
| Relais : `API (message) injoignable : …` | Hébergement en panne, DNS ou certificat : le relais réessaie tout seul |
| Relais : `supprimé de Mailpit avant d'avoir été relayé` | Mail supprimé avant d'être relayé (à la main, ou par `MP_MAX_MESSAGES` / `MP_MAX_AGE`) : il ne peut plus être envoyé |
| Relais : `Mailpit … : HTTP 400` sur la recherche | Filtre `RELAY_QUERY` mal écrit (syntaxe de recherche de Mailpit) |
| Relais : `ping de santé non enregistré : …` | Le ping (`POST /v1/health`) a échoué. Même causes que pour les mails (`401` jeton, `403` IP, API injoignable…). `HTTP 404` : API d'une version précédente, sans cette route (mettez-la à jour). `HTTP 500` : migration `2026-11-health-pings.sql` pas importée. Écrit une fois, puis toutes les 10 minutes tant que ça dure ; `ping de santé de nouveau accepté` au retour |
| Relais : `ping de santé envoyé sans les compteurs : …` | Mailpit ne répond pas au ping : la page **Statut** affiche « inconnu » pour les mails en attente et refusés. Mêmes causes que les erreurs `Mailpit …` ci-dessus |
| Relais : `le ping de santé s'est arrêté`, puis redémarrage | Le processus du ping a planté : le relais s'arrête en erreur et systemd le redémarre. Si ça se répète, regardez les lignes juste avant dans le journal |
| Page **Statut** de l'API : « Déconnecté depuis … » | Aucun ping depuis plus de 90 secondes : relais arrêté (`sudo systemctl status mailpit-relay`), serveur ou réseau coupé, ou API en panne. Le journal du relais dit lequel |

> ℹ️ Vous utilisiez aussi `host.containers.internal` avec ufw (dépannage de la [Partie 6](06-webhook.md#dépannage-webhook)) ? Le réseau `mailpit` utilise le sous-réseau `10.89.25.0/24`, et non `10.88.0.0/16` : adaptez la règle ufw.

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
