# Partie 1 — Installer Mailpit avec Podman

> Remplacez les valeurs d'exemple (`mondomaine.fr`, `admin:MotDePasseSolide`…) comme indiqué dans [Avant de commencer](../mailpit.md#avant-de-commencer).

On lance Mailpit en **root** (« rootful »). C'est le plus simple sur un serveur : le port 25 fonctionne sans réglage système et le service démarre au boot sans configuration supplémentaire. La version sans root est en [fin de page](#pour-aller-plus-loin).

## Étape 1 — Installer Podman et les outils de test

```bash
sudo apt update
sudo apt install -y podman swaks
podman --version
```

`swaks` est un petit outil pour envoyer des mails de test en ligne de commande.

## Étape 2 — Vérifier que le port 25 est libre

```bash
sudo ss -ltnp | grep ':25 '
```

- **Aucune ligne** : parfait, passez à la suite.
- **Une ligne avec `exim4` ou `postfix`** : un serveur mail occupe déjà le port. Arrêtez-le s'il ne vous sert pas :
  ```bash
  sudo systemctl disable --now exim4    # ou postfix
  ```

## Étape 3 — Créer le fichier de configuration de Mailpit

```bash
sudo mkdir -p /etc/mailpit
sudo nano /etc/mailpit/mailpit.env
```

Contenu (adaptez le domaine et le mot de passe) :

```ini
# Stockage sur disque (sinon les mails disparaissent au redémarrage)
MP_DATABASE=/data/mailpit.db

# Garde au maximum 5000 mails, et pas plus de 30 jours
MP_MAX_MESSAGES=5000
MP_MAX_AGE=30d

# N'accepte QUE les mails destinés au sous-domaine de test (expression régulière)
MP_SMTP_ALLOWED_RECIPIENTS=@test\.mondomaine\.fr$

# Identifiants de l'interface web et de l'API (pas d'espace dans le mot de passe)
MP_UI_AUTH=admin:MotDePasseSolide

TZ=Europe/Paris
```

Protégez le fichier, car il contient un mot de passe :

```bash
sudo chmod 600 /etc/mailpit/mailpit.env
```

> ℹ️ Ne mettez **pas** de `MP_SMTP_AUTH`. Les serveurs qui vous envoient des mails depuis Internet (Gmail, etc.) ne s'authentifient pas : avec une authentification SMTP obligatoire, ils seraient tous refusés. C'est `MP_SMTP_ALLOWED_RECIPIENTS` qui filtre les mails acceptés.

> ⚠️ Gardez les `\` devant les points dans `MP_SMTP_ALLOWED_RECIPIENTS` (sans eux, un point veut dire « n'importe quel caractère »), et ne mettez pas de guillemets autour des valeurs.

## Étape 4 — Créer le service systemd (Quadlet)

```bash
sudo mkdir -p /etc/containers/systemd
sudo nano /etc/containers/systemd/mailpit.container
```

Contenu :

```ini
[Unit]
Description=Mailpit - capture des e-mails de test
Wants=network-online.target
After=network-online.target

[Container]
ContainerName=mailpit
Image=docker.io/axllent/mailpit:latest
EnvironmentFile=/etc/mailpit/mailpit.env
Volume=mailpit-data:/data

# SMTP public : port 25 du serveur -> port 1025 de Mailpit
PublishPort=25:1025
# Interface web : accessible uniquement depuis le serveur lui-même
PublishPort=127.0.0.1:8025:8025

# Permet les mises à jour automatiques (voir « Pour aller plus loin »)
AutoUpdate=registry

[Service]
Restart=always

[Install]
WantedBy=multi-user.target
```

Points importants :

- `docker.io/…` : écrivez le nom **complet** de l'image, Podman n'a pas de registre par défaut sous Debian.
- `127.0.0.1:8025` : l'interface web n'est **pas** exposée sur Internet. On y accède par tunnel SSH (étape 7).
- Le fichier doit se terminer par `.container` : c'est cette extension que Quadlet reconnaît.

Vérifiez que Quadlet comprend le fichier :

```bash
sudo /usr/lib/systemd/system-generators/podman-system-generator --dryrun 2>&1 | head -40
```

Vous devez voir un bloc `---mailpit.service---` avec une ligne `ExecStart=/usr/bin/podman run ...`. Un message d'erreur qui cite `mailpit.container` signale une faute de frappe.

## Étape 5 — Démarrer le service

Téléchargez d'abord l'image. Sinon, le premier démarrage la télécharge, et sur une connexion lente il peut dépasser le délai de démarrage de systemd :

```bash
sudo podman pull docker.io/axllent/mailpit:latest
```

Puis :

```bash
sudo systemctl daemon-reload
sudo systemctl start mailpit
sudo systemctl status mailpit
```

> ℹ️ Pas besoin de `systemctl enable` : avec Quadlet, c'est la section `[Install]` qui fait démarrer le service au boot. La commande `enable` renverrait même une erreur.

Vérifications :

```bash
sudo podman ps                         # le conteneur "mailpit" doit être "Up"
sudo journalctl -u mailpit -n 30       # les logs de Mailpit
```

Test d'envoi **depuis le serveur lui-même** :

```bash
# Doit être ACCEPTÉ : code 250 à la fin
swaks --server 127.0.0.1 --port 25 --to essai@test.mondomaine.fr

# Doit être REFUSÉ : code d'erreur 5xx après RCPT TO
swaks --server 127.0.0.1 --port 25 --to quelquun@gmail.com
```

Vérifiez que le premier mail est bien arrivé. Le résultat doit contenir `"total":1`. Avec `-u admin` sans mot de passe, curl vous le demande, et il ne reste pas dans l'historique du shell :

```bash
curl -s -u admin http://127.0.0.1:8025/api/v1/messages | head -c 400
```

## Étape 6 — Ouvrir le port 25 dans le pare-feu

Debian n'active pas de pare-feu par défaut. Vérifiez avec `sudo ufw status` (« inactive » ou « command not found » : rien à faire ici).

**Si ufw est actif**, `ufw allow 25/tcp` ne suffit **pas**. Podman redirige les connexions du port 25 vers le conteneur, qui est sur son propre réseau (`10.88.0.0/16` par défaut), port 1025. Ce trafic est donc **routé** : il passe par la chaîne `FORWARD`, que ufw bloque par défaut. Il faut une règle de routage :

```bash
sudo ufw route allow proto tcp to 10.88.0.0/16 port 1025 comment 'Mailpit SMTP (Podman)'
sudo ufw status                 # doit afficher : 10.88.0.0/16 1025/tcp  ALLOW FWD  Anywhere
```

Pour vérifier le réseau du conteneur : `sudo podman network inspect podman | grep subnet`.

Si vous avez activé le **pare-feu réseau OVH** (« Edge Network Firewall ») sur l'IP du serveur dans l'espace client, autorisez-y aussi le TCP 25 entrant.

### Serveur à la maison (derrière une box)

Si Mailpit tourne sur une machine chez vous, Internet ne voit que **l'IP publique de la box**. Dans ce cas :

1. **Vérifiez que votre opérateur ne bloque pas le port 25 entrant.** Certaines offres grand public le bloquent ou le rendent désactivable dans l'espace client. Sans ce port, rien n'arrivera.
2. **Fixez l'IP locale de la machine** : dans la box, créez un bail DHCP statique (« réservation d'adresse »). Pour trouver cette IP, lancez `ip -4 addr show` et prenez l'adresse `192.168.x.x`, pas `10.88.x.x` qui est celle de Podman.
3. **Ajoutez une redirection de port** dans la box (« NAT/PAT », « Redirection de ports ») : TCP, port externe **25** → IP locale de la machine, port **25**. N'ouvrez **pas** le 8025.
4. Dans la Partie 2, `IP_DU_SERVEUR` est l'**IP publique de la box** : `curl -4 -s https://ifconfig.me`.
5. La machine doit rester allumée, sans mise en veille.

> ℹ️ Depuis votre réseau local, une connexion vers votre propre IP publique échoue souvent, même si tout est bien configuré : beaucoup de box ne gèrent pas ce « retour » (le *hairpin NAT*). Testez depuis l'extérieur, ou envoyez un vrai mail ([Partie 3](03-test.md)).

## Étape 7 — Accéder à l'interface web

Si vous êtes directement sur le serveur (par exemple une machine chez vous), ouvrez simplement **http://localhost:8025**.

Sinon, depuis **votre ordinateur**, ouvrez un tunnel SSH :

```bash
ssh -L 8025:127.0.0.1:8025 utilisateur@IP_DU_SERVEUR
```

Laissez cette fenêtre ouverte, puis ouvrez **http://localhost:8025** dans votre navigateur et connectez-vous avec les identifiants de `MP_UI_AUTH`.

---

## Dépannage

| Symptôme | Cause probable / solution |
|---|---|
| `systemctl start mailpit` → « Unit not found » | Erreur dans le fichier `.container`. Diagnostic : `sudo /usr/lib/systemd/system-generators/podman-system-generator --dryrun` |
| Le service démarre puis s'arrête | Lire `sudo journalctl -u mailpit -n 50`. Souvent : port 25 déjà pris (étape 2) |
| Interface web : erreur 401 | Identifiants différents de ceux de `MP_UI_AUTH` |
| Les mails disparaissent au redémarrage | `MP_DATABASE` absent du fichier env ou volume non monté |

Pour les problèmes de réception depuis Internet, voir le [dépannage de la Partie 3](03-test.md#dépannage).

---

## Pour aller plus loin

**Mises à jour automatiques de l'image** (grâce à `AutoUpdate=registry`) :

```bash
sudo systemctl enable --now podman-auto-update.timer
```

**Chiffrement STARTTLS** : voir la [Partie 4](04-tls.md).

**Entretien courant** (mises à jour, certificat, sauvegardes) : voir la [Partie 5](05-maintenance.md).

**Prévenir une autre application à chaque mail reçu** (webhook) : voir la [Partie 6](06-webhook.md).

**Interface web en HTTPS sur un vrai nom** (au lieu du tunnel SSH) : mettez un reverse proxy (Caddy ou nginx) devant `127.0.0.1:8025`. Gardez `MP_UI_AUTH` activé, et pensez à transmettre les WebSockets, sinon la liste des mails ne se met pas à jour en temps réel.

**Version sans root (rootless)**, si vous préférez ne rien lancer en root :

1. Autorisez les utilisateurs à ouvrir le port 25 :
   `echo 'net.ipv4.ip_unprivileged_port_start=25' | sudo tee /etc/sysctl.d/99-ports.conf && sudo sysctl --system`
2. Permettez aux services de l'utilisateur de tourner sans session ouverte : `sudo loginctl enable-linger $USER`
3. Placez le fichier `.container` dans `~/.config/containers/systemd/`, mettez `WantedBy=default.target`, puis utilisez `systemctl --user daemon-reload` et `systemctl --user start mailpit`.

---

## Annexe — Debian 12

Debian 12 (Bookworm) fournit Podman 4.3, **sans Quadlet**. Le plus simple est de passer en Debian 13. Sinon, créez le conteneur à la main, puis générez un service systemd :

```bash
sudo podman run -d --name mailpit \
  --env-file /etc/mailpit/mailpit.env \
  -v mailpit-data:/data \
  -p 25:1025 -p 127.0.0.1:8025:8025 \
  docker.io/axllent/mailpit:latest

cd /etc/systemd/system
sudo podman generate systemd --new --files --name mailpit
sudo podman rm -f mailpit
sudo systemctl daemon-reload
sudo systemctl enable --now container-mailpit.service
```

Ici, `systemctl enable` est bien nécessaire. Le service s'appelle `container-mailpit` : remplacez `mailpit` par `container-mailpit` dans les commandes `systemctl` et `journalctl` des autres parties. Pour le TLS, voir aussi la [note Debian 12 de la Partie 4](04-tls.md#debian-12).

---

[Sommaire](../mailpit.md) · [→](02-ovh-dns-zone.md)