# Partie 4 — Activer le TLS (STARTTLS) avec Certbot

> Remplacez les valeurs d'exemple (`mail.mondomaine.fr`, `vous@exemple.fr`…) comme indiqué dans [Avant de commencer](../mailpit.md#avant-de-commencer).

Sans TLS, les mails arrivent quand même, mais **en clair** sur Internet. Avec un certificat Let's Encrypt pour `mail.mondomaine.fr`, Mailpit propose **STARTTLS**, et les serveurs expéditeurs (Gmail, Outlook…) chiffrent la connexion.

Principe : Certbot obtient le certificat et le renouvelle tout seul. À chaque obtention ou renouvellement, un petit script (un « deploy hook ») copie le certificat dans `/etc/mailpit/certs/` et redémarre Mailpit. Le conteneur ne voit que ces deux fichiers, pas tout `/etc/letsencrypt`.

> Prérequis : l'enregistrement A `mail.mondomaine.fr` ([Partie 2, étape 2](02-ovh-dns-zone.md#étape-2--ajouter-lenregistrement-a)) doit **déjà répondre** : `dig +short A mail.mondomaine.fr` affiche l'IP du serveur.

## Étape 1 — Installer Certbot et le plugin OVH

Debian 13 ne fournit **pas** le plugin OVH (`python3-certbot-dns-ovh` n'existe pas dans ses dépôts), et un plugin installé par pip est invisible pour le Certbot de Debian. On installe donc Certbot **et** le plugin depuis PyPI, dans un environnement Python dédié. C'est la méthode d'installation officielle de Certbot :

```bash
sudo apt remove -y certbot          # au cas où le paquet Debian serait déjà installé
sudo apt install -y python3-venv
sudo python3 -m venv /opt/certbot
sudo /opt/certbot/bin/pip install --upgrade pip
sudo /opt/certbot/bin/pip install certbot certbot-dns-ovh
sudo ln -s /opt/certbot/bin/certbot /usr/local/bin/certbot
sudo certbot plugins                # doit lister dns-ovh
```

Pour le renouvellement automatique, créez un timer systemd :

```bash
sudo nano /etc/systemd/system/certbot-renew.service
```

```ini
[Unit]
Description=Renouvellement des certificats Let's Encrypt

[Service]
Type=oneshot
ExecStart=/opt/certbot/bin/certbot renew -q
```

```bash
sudo nano /etc/systemd/system/certbot-renew.timer
```

```ini
[Unit]
Description=Renouvellement Let's Encrypt deux fois par jour

[Timer]
OnCalendar=*-*-* 00,12:00:00
RandomizedDelaySec=1h
Persistent=true

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now certbot-renew.timer
```

> ℹ️ Cette installation n'est **pas** mise à jour par `apt upgrade`. Voir la [Partie 5 — Maintenance](05-maintenance.md#certbot).

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

## Étape 3 — Obtenir le certificat (validation DNS via l'API OVH)

Let's Encrypt doit vérifier que vous contrôlez le domaine. Ici, Certbot crée lui-même un enregistrement TXT temporaire (`_acme-challenge.mail.mondomaine.fr`) dans votre zone OVH, puis le supprime. **Aucun port supplémentaire n'est ouvert** (ni 80, ni 443) : c'est idéal pour un serveur derrière une box.

1. Créez des identifiants d'API sur **https://eu.api.ovh.com/createToken/** :
   - *Application name* / *description* : par ex. `certbot-mailpit` ;
   - *Validity* : **Unlimited**, sinon les renouvellements échoueront à l'expiration du jeton ;
   - *Rights*, en limitant l'écriture à votre seule zone :

     | Méthode | Chemin |
     |---|---|
     | GET | `/domain/zone/*` |
     | POST | `/domain/zone/mondomaine.fr/*` |
     | PUT | `/domain/zone/mondomaine.fr/*` |
     | DELETE | `/domain/zone/mondomaine.fr/*` |

   Notez l'*Application Key*, l'*Application Secret* et la *Consumer Key* : ils ne sont affichés qu'une fois.
2. Enregistrez-les sur le serveur :
   ```bash
   sudo install -d -m 700 /root/.secrets
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
3. Obtenez le certificat :
   ```bash
   sudo certbot certonly --dns-ovh \
     --dns-ovh-credentials /root/.secrets/ovh.ini \
     --dns-ovh-propagation-seconds 60 \
     -d mail.mondomaine.fr \
     -m vous@exemple.fr --agree-tos --no-eff-email \
     --deploy-hook /usr/local/bin/mailpit-cert-deploy.sh
   ```
   Certbot enregistre ces options (plugin, fichier d'identifiants, deploy hook) dans `/etc/letsencrypt/renewal/mail.mondomaine.fr.conf` : les renouvellements automatiques les réutilisent.

   Le message « Certbot has set up a scheduled task » s'affiche toujours, même avec l'installation par pip. Ici, c'est **votre** timer `certbot-renew.timer` (étape 1) qui renouvelle le certificat : vérifiez qu'il est actif avec `systemctl list-timers | grep certbot-renew`.

> ⚠️ Ces identifiants permettent de modifier votre zone DNS : protégez `ovh.ini` comme un mot de passe, et ne le mettez jamais dans un dépôt Git. En cas de fuite, révoquez le jeton depuis la console API OVH (`https://eu.api.ovh.com/console/`) : `GET /me/api/credential` pour trouver son identifiant, puis `DELETE /me/api/credential/{credentialId}`.

Vérifiez que le script a bien copié les fichiers :

```bash
sudo ls -l /etc/mailpit/certs/      # fullchain.pem et privkey.pem
```

## Étape 4 — Brancher le certificat sur Mailpit

1. Ajoutez à la fin de `/etc/mailpit/mailpit.env` ([Partie 1, étape 3](01-podman.md#étape-3--créer-le-fichier-de-configuration-de-mailpit)) :
   ```ini
   # Certificat pour STARTTLS (chemins vus depuis le conteneur)
   MP_SMTP_TLS_CERT=/certs/fullchain.pem
   MP_SMTP_TLS_KEY=/certs/privkey.pem
   ```
2. Dans `/etc/containers/systemd/mailpit.container` ([Partie 1, étape 4](01-podman.md#étape-4--créer-le-service-systemd-quadlet)), ajoutez cette ligne dans la section `[Container]`, sous `Volume=mailpit-data:/data` :
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
systemctl list-timers | grep certbot     # certbot-renew.timer
```

**Depuis l'extérieur** : les tests ci-dessus se font en local. Pour vérifier ce que voient vraiment les expéditeurs (à travers la box ou le pare-feu), utilisez **https://www.checktls.com/TestReceiver** avec une adresse comme `essai@test.mondomaine.fr`. Le rapport doit afficher `SSL/TLS is working correctly on this server`.

> ℹ️ Ne vous fiez pas à l'en-tête `Received:` ajouté par Mailpit (il indique `with SMTP` et ne précise pas si la connexion était chiffrée), ni à la ligne « Sécurité » des détails d'un mail envoyé depuis Gmail, qui ne s'affiche pas toujours.
>
> Pour voir **à coup sûr** si un expéditeur utilise STARTTLS, observez l'échange pendant un envoi : `sudo tcpdump -l -i any -nn -A 'tcp port 25' 2>/dev/null | grep -aE 'EHLO|STARTTLS|MAIL FROM'` (paquet `tcpdump`). Si vous voyez `STARTTLS` puis plus aucune commande lisible, la connexion est chiffrée. Si vous voyez `MAIL FROM` en clair, elle ne l'est pas.

---

## Dépannage TLS

| Symptôme | Cause probable / solution |
|---|---|
| Certbot : `403` / « This call has not been granted » | Droits du jeton OVH insuffisants (voir le tableau de l'étape 3) ou mauvaise zone dans les chemins. Recréez un jeton |
| Certbot : « Invalid credentials » / « Invalid signature » | Faute de frappe dans `ovh.ini`, ou mauvais `dns_ovh_endpoint` (`ovh-eu` pour les comptes européens) |
| Certbot : « Incorrect TXT record » / « No TXT record found » | Le TXT n'était pas encore publié : relancez avec `--dns-ovh-propagation-seconds 120` |
| `certbot renew` échoue des mois plus tard | Jeton OVH expiré ou révoqué : recréez-le avec une validité illimitée et mettez à jour `ovh.ini` |
| Mailpit ne redémarre plus après l'étape 4 | `sudo journalctl -u mailpit -n 50`. Vérifiez que `/etc/mailpit/certs/` contient bien les deux fichiers et que la ligne `Volume=` est dans `[Container]` |
| Test local OK mais checktls ne voit pas STARTTLS | Un équipement entre Internet et le serveur filtre le SMTP (option « protection SMTP » / SMTP ALG de certaines box ou routeurs) : désactivez-la |
| `Verify return code` différent de 0 | Vous avez mis `cert.pem` au lieu de `fullchain.pem`, ou ce n'est pas le bon certificat |
| Relancer la copie à la main (après un changement de config, par ex.) | `sudo RENEWED_LINEAGE=/etc/letsencrypt/live/mail.mondomaine.fr /usr/local/bin/mailpit-cert-deploy.sh` |

---

## Variantes

**Version rootless** : dans le script, ajoutez un `chown` des fichiers vers votre utilisateur. Remplacez aussi la dernière ligne par `systemctl --user -M votreuser@ restart mailpit`.

**Sans domaine public / pour un test rapide** : `MP_SMTP_TLS_CERT=sans:mail.mondomaine.fr` et `MP_SMTP_TLS_KEY=sans:mail.mondomaine.fr` font générer à Mailpit un certificat auto-signé. Le chiffrement fonctionne, mais le certificat n'est pas reconnu comme fiable.

### Debian 12

Si vous avez suivi l'[annexe Debian 12](01-podman.md#annexe--debian-12) :

- le service s'appelle `container-mailpit` : adaptez la dernière ligne du script de déploiement ;
- au lieu de l'étape 4.2, ajoutez `-v /etc/mailpit/certs:/certs:ro` à la commande `podman run`, puis régénérez le service avec les mêmes commandes que dans l'annexe.

---

[←](03-test.md) · [Sommaire](../mailpit.md) · [→](05-maintenance.md)