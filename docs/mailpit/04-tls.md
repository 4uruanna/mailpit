# Partie 4 — Activer le TLS (STARTTLS) avec Certbot

> Remplacez les valeurs d'exemple (`mail.mondomaine.fr`, `vous@exemple.fr`…) comme indiqué dans [Avant de commencer](mailpit.md#avant-de-commencer).

Sans TLS, les mails arrivent quand même, mais **en clair** sur Internet. Avec un certificat Let's Encrypt pour `mail.mondomaine.fr`, Mailpit propose **STARTTLS**, et les serveurs expéditeurs (Gmail, Outlook…) chiffrent la connexion.

Principe : Certbot obtient le certificat et le renouvelle tout seul. À chaque obtention ou renouvellement, un petit script (un « deploy hook ») copie le certificat dans `/etc/mailpit/certs/` et redémarre Mailpit. Le conteneur ne voit que ces deux fichiers, pas tout `/etc/letsencrypt`.

> Prérequis : l'enregistrement A `mail.mondomaine.fr` ([Partie 2, étape 2](ovh-dns-zone.md#étape-2--ajouter-lenregistrement-a)) doit **déjà répondre** : `dig +short A mail.mondomaine.fr` affiche l'IP du serveur.

## Étape 1 — Installer Certbot

```bash
sudo apt install -y certbot
```

Le paquet Debian installe aussi un timer systemd qui tente le renouvellement deux fois par jour. Vous n'avez rien à programmer.

## Étape 2 — Créer le script de déploiement

```bash
sudo nano /usr/local/bin/mailpit-cert-deploy.sh
```

Contenu :

```sh
#!/bin/sh
# Appelé par Certbot après chaque obtention / renouvellement du certificat.
# $RENEWED_LINEAGE est fourni par Certbot (ex. /etc/letsencrypt/live/mail.mondomaine.fr)
set -e
DST=/etc/mailpit/certs
install -d -m 700 "$DST"
install -m 600 "$RENEWED_LINEAGE/fullchain.pem" "$DST/fullchain.pem"
install -m 600 "$RENEWED_LINEAGE/privkey.pem"   "$DST/privkey.pem"
systemctl restart mailpit
```

Rendez-le exécutable :

```bash
sudo chmod 755 /usr/local/bin/mailpit-cert-deploy.sh
```

## Étape 3 — Obtenir le certificat

Choisissez **une** des deux méthodes.

### Méthode A — Validation HTTP (la plus simple)

Let's Encrypt vérifie que vous contrôlez le domaine en se connectant au **port 80** du serveur. Ce port doit donc être **libre** (pas de nginx ou d'Apache dessus) et **ouvert** :

```bash
sudo ss -ltnp | grep ':80 '     # aucune ligne = port libre
sudo ufw allow 80/tcp           # si vous utilisez ufw (et pare-feu réseau OVH le cas échéant)
```

Puis :

```bash
sudo certbot certonly --standalone \
  -d mail.mondomaine.fr \
  -m vous@exemple.fr --agree-tos --no-eff-email \
  --deploy-hook /usr/local/bin/mailpit-cert-deploy.sh
```

> Le port 80 doit rester ouvert : Certbot s'en resservira à chaque renouvellement (tous les ~60 jours).

### Méthode B — Validation DNS via l'API OVH (si le port 80 est pris ou fermé)

Certbot crée lui-même un enregistrement temporaire dans votre zone DNS OVH. Aucun port n'a besoin d'être ouvert.

1. Installez le plugin :
   ```bash
   sudo apt install -y python3-certbot-dns-ovh
   ```
2. Créez des identifiants d'API sur **https://eu.api.ovh.com/createToken/**. Donnez les droits **GET, POST, PUT, DELETE** sur `/domain/zone/*` et une validité illimitée. Notez l'*Application Key*, l'*Application Secret* et la *Consumer Key*.
3. Enregistrez-les sur le serveur :
   ```bash
   sudo mkdir -p /root/.secrets
   sudo nano /root/.secrets/ovh.ini
   ```
   ```ini
   dns_ovh_endpoint = ovh-eu
   dns_ovh_application_key = VOTRE_APPLICATION_KEY
   dns_ovh_application_secret = VOTRE_APPLICATION_SECRET
   dns_ovh_consumer_key = VOTRE_CONSUMER_KEY
   ```
   ```bash
   sudo chmod 600 /root/.secrets/ovh.ini
   ```
4. Obtenez le certificat :
   ```bash
   sudo certbot certonly --dns-ovh \
     --dns-ovh-credentials /root/.secrets/ovh.ini \
     --dns-ovh-propagation-seconds 60 \
     -d mail.mondomaine.fr \
     -m vous@exemple.fr --agree-tos --no-eff-email \
     --deploy-hook /usr/local/bin/mailpit-cert-deploy.sh
   ```

> ⚠️ Ces identifiants permettent de modifier votre zone DNS : protégez le fichier `ovh.ini` comme un mot de passe.

**Dans les deux cas**, vérifiez que le script a bien copié les fichiers :

```bash
sudo ls -l /etc/mailpit/certs/      # fullchain.pem et privkey.pem
```

## Étape 4 — Brancher le certificat sur Mailpit

1. Ajoutez à la fin de `/etc/mailpit/mailpit.env` ([Partie 1, étape 3](podman.md#étape-3--créer-le-fichier-de-configuration-de-mailpit)) :
   ```ini
   # Certificat pour STARTTLS (chemins vus depuis le conteneur)
   MP_SMTP_TLS_CERT=/certs/fullchain.pem
   MP_SMTP_TLS_KEY=/certs/privkey.pem
   ```
2. Dans `/etc/containers/systemd/mailpit.container` ([Partie 1, étape 4](podman.md#étape-4--créer-le-service-systemd-quadlet)), ajoutez cette ligne dans la section `[Container]`, sous `Volume=mailpit-data:/data` :
   ```ini
   Volume=/etc/mailpit/certs:/certs:ro
   ```
3. Rechargez et redémarrez :
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl restart mailpit
   sudo systemctl status mailpit
   ```

> ℹ️ N'activez **pas** l'option qui rend STARTTLS obligatoire (`MP_SMTP_REQUIRE_STARTTLS`). Certains expéditeurs ne savent pas faire de TLS : leurs mails seraient refusés. Proposer le TLS suffit, les serveurs modernes l'utilisent automatiquement.

## Étape 5 — Vérifier

Le certificat présenté sur le port 25 :

```bash
openssl s_client -starttls smtp -connect 127.0.0.1:25 -servername mail.mondomaine.fr </dev/null 2>/dev/null \
  | grep -E 'subject=|issuer=|Verify return code'
```

Résultat attendu : `subject` contient `CN=mail.mondomaine.fr`, `issuer` contient `Let's Encrypt`, et la ligne `Verify return code: 0 (ok)`.

Un envoi de test en TLS :

```bash
swaks --server 127.0.0.1 --port 25 --to essai@test.mondomaine.fr --tls
```

Le renouvellement automatique (simulation, ne change rien) :

```bash
sudo certbot renew --dry-run
systemctl list-timers | grep certbot
```

---

## Dépannage TLS

| Symptôme | Cause probable / solution |
|---|---|
| Certbot : « Could not bind TCP port 80 » | Un serveur web occupe le port 80 → méthode B (DNS) |
| Certbot : « Timeout during connect » | Port 80 fermé (ufw, pare-feu OVH) ou enregistrement A pas encore propagé |
| Mailpit ne redémarre plus après l'étape 4 | `sudo journalctl -u mailpit -n 50`. Vérifiez que `/etc/mailpit/certs/` contient bien les deux fichiers et que la ligne `Volume=` est dans `[Container]` |
| `Verify return code` différent de 0 | Vous avez mis `cert.pem` au lieu de `fullchain.pem`, ou ce n'est pas le bon certificat |
| Relancer la copie à la main (après un changement de config, par ex.) | `sudo RENEWED_LINEAGE=/etc/letsencrypt/live/mail.mondomaine.fr /usr/local/bin/mailpit-cert-deploy.sh` |

---

## Variantes

**Version rootless** : dans le script, ajoutez un `chown` des fichiers vers votre utilisateur. Remplacez aussi la dernière ligne par `systemctl --user -M votreuser@ restart mailpit`.

**Sans domaine public / pour un test rapide** : `MP_SMTP_TLS_CERT=sans:mail.mondomaine.fr` et `MP_SMTP_TLS_KEY=sans:mail.mondomaine.fr` font générer à Mailpit un certificat auto-signé. Le chiffrement fonctionne, mais le certificat n'est pas reconnu comme fiable.

### Debian 12

Si vous avez suivi l'[annexe Debian 12](podman.md#annexe--debian-12) :

- le service s'appelle `container-mailpit` : adaptez la dernière ligne du script de déploiement ;
- au lieu de l'étape 4.2, ajoutez `-v /etc/mailpit/certs:/certs:ro` à la commande `podman run`, puis régénérez le service avec les mêmes commandes que dans l'annexe.

---

[←](test.md) · [Sommaire](mailpit.md)