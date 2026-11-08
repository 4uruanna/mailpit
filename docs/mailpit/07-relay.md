# Partie 7 — Relayer le contenu complet des mails vers une API

> Remplacez les valeurs d'exemple (`mondomaine.fr`, `admin:MotDePasseSolide`…) comme indiqué dans [Avant de commencer](../mailpit.md#avant-de-commencer). `api.mondomaine.fr` désigne ici l'API du dossier [`api/`](../../api/README.md), déployée sur un hébergement web.

Le webhook de la [Partie 6](06-webhook.md) n'envoie qu'un **résumé** du mail. Le contenu complet n'est lisible que par l'API de Mailpit, qui n'écoute que sur le serveur (`127.0.0.1:8025`). Une API hébergée ailleurs ne peut donc pas le récupérer.

Le **relais** (dossier [`relay/`](../../relay/README.md) du dépôt) comble ce manque. C'est un petit service PHP qui tourne dans Podman, à côté de Mailpit :

```
                    serveur Debian (réseau Podman privé « mailpit »)
┌──────────────────────────────────────────────────────────────────────┐
│  Mailpit ── 1. webhook (résumé) ──► mailpit-relay                    │
│     ▲                                   │  répond tout de suite,     │
│     └──── 2. lit le mail complet ───────┤  file d'attente sur disque │
└─────────────────────────────────────────┼────────────────────────────┘
                                          │ 3. POST : résumé + texte + HTML (+ .eml)
                                          ▼
                        https://api.mondomaine.fr/hook/mailpit
```

Ce que le relais apporte, en plus du contenu :

- **Pas de mail perdu si l'API est en panne.** Il réessaie pendant environ 2 jours (30 s, 1 min, 2 min… puis toutes les heures). Mailpit, lui, n'essaie qu'une fois.
- **Rien n'est exposé.** L'interface de Mailpit reste sur `127.0.0.1`, et le relais n'ouvre aucun port sur le serveur.

**Prérequis :**

- Parties 1 à 3 terminées : Mailpit reçoit des mails.
- L'API déployée sur l'hébergement, avec le schéma SQL à jour (voir son [README](../../api/README.md)). L'IP du serveur Mailpit doit être dans `allowed_ips` : le relais tourne sur ce serveur, il appelle donc l'API depuis la même IP.
- **Debian 13** (Quadlet). Pour Debian 12, voir l'[annexe](#annexe--debian-12).

## Étape 1 — Installer les outils

```bash
sudo apt install -y git aardvark-dns
```

`aardvark-dns` permet aux conteneurs d'un même réseau Podman de se joindre **par leur nom** (`mailpit`, `mailpit-relay`). Il est souvent déjà installé avec Podman.

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
# API qui reçoit les mails (avec le jeton webhook.token de config.php, si vous l'utilisez)
RELAY_TARGET_URL=https://api.mondomaine.fr/hook/mailpit?token=LE_JETON

# Mailpit, vu depuis le relais : le nom du conteneur sur le réseau « mailpit »
RELAY_MAILPIT_URL=http://mailpit:8025

# Les identifiants de MP_UI_AUTH (fichier mailpit.env)
RELAY_MAILPIT_AUTH=admin:MotDePasseSolide

# true : joint aussi le mail brut (.eml avec les pièces jointes, 5 Mo max par défaut)
RELAY_INCLUDE_RAW=false
```

Protégez le fichier, car il contient des mots de passe :

```bash
sudo chmod 600 /etc/mailpit/relay.env
```

> ℹ️ Avec `RELAY_INCLUDE_RAW=true`, l'API enregistre chaque mail brut en fichier `.eml` dans son dossier `var/messages/`. Pensez à la taille : l'espace d'un hébergement mutualisé est limité.

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
# File d'attente : conservée si le conteneur est recréé
Volume=mailpit-relay-data:/data
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

`MP_WEBHOOK_LIMIT=0` est indispensable. Sinon, les mails arrivés dans la même seconde ne déclenchent aucun appel, et le relais ne les voit jamais.

## Étape 8 — Démarrer

```bash
sudo systemctl daemon-reload
sudo systemctl restart mailpit          # recrée le conteneur sur le réseau « mailpit »
sudo systemctl start mailpit-relay
sudo systemctl status mailpit mailpit-relay
```

Les logs du relais doivent afficher une ligne `démarrage : écoute sur 0.0.0.0:8080…` qui résume la configuration (le jeton y est masqué) :

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

Dans un second, envoyez un mail de test :

```bash
swaks --server 127.0.0.1 --port 25 --to essai@test.mondomaine.fr
```

En une ou deux secondes, vous devez voir :

```
[relay] INFO  mail Wdyx4jVvBWELmoNePrh8Ff reçu : test Sun, 08 Nov 2026 …
[relay] INFO  mail Wdyx4jVvBWELmoNePrh8Ff envoyé à l'API (HTTP 201, essai 1)
```

Le mail apparaît dans la table `mailpit_messages` de l'API, avec ses colonnes `text_body` et `html_body` remplies. Dans l'interface de Mailpit, il reste **non lu**.

---

## Au quotidien

**État de la file d'attente** (mails en attente d'un nouvel essai, mails abandonnés et la dernière erreur de chacun) :

```bash
sudo podman exec mailpit-relay php /app/bin/status.php
```

**Relancer les mails abandonnés**, une fois la cause corrigée (IP non autorisée, jeton, API en panne plus de 2 jours…) :

```bash
sudo podman exec mailpit-relay php /app/bin/retry-failed.php
```

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

Un arrêt du relais ne fait perdre aucun mail **déjà reçu** : la file d'attente est dans le volume `mailpit-relay-data`. En revanche, un mail arrivé dans Mailpit **pendant** l'arrêt ne sera jamais relayé, car Mailpit n'envoie le webhook qu'une fois. Évitez de laisser le relais arrêté.

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
| Relais : `API : HTTP 403` | IP du serveur absente de `allowed_ips` (pensez à l'IPv6), ou jeton faux dans `RELAY_TARGET_URL`. Le relais réessaie : corrigez l'API, il n'y a rien à relancer |
| Relais : `refusé par l'API (HTTP 413)` | Mail trop gros pour l'API : augmentez `max_body_size` dans son `config.php`, ou baissez `RELAY_MAX_RAW_SIZE`, puis lancez `retry-failed.php` |
| Relais : `API injoignable : …` | Hébergement en panne, DNS ou certificat : le relais réessaie tout seul |
| Relais : `introuvable dans Mailpit (supprimé ?)` | Mail supprimé avant d'être relayé (à la main, ou par `MP_MAX_MESSAGES` / `MP_MAX_AGE`) : seul le résumé est envoyé |

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
  -v mailpit-relay-data:/data \
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

[←](06-webhook.md) · [Sommaire](../mailpit.md)
