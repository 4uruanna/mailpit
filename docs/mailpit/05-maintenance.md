# Partie 5 — Maintenance

> Remplacez les valeurs d'exemple (`mail.mondomaine.fr`, `admin`…) comme indiqué dans [Avant de commencer](../mailpit.md#avant-de-commencer).

Une fois installé, le service tourne tout seul. Quelques vérifications régulières suffisent pour qu'il **reste** à jour, joignable, et que le certificat ne périme pas en silence.

## Récapitulatif

| Quand | Quoi | Section |
|---|---|---|
| Chaque mois | Mettre à jour Debian, vérifier que le service tourne, vérifier la date d'expiration du certificat | [Système](#système-debian), [État du service](#vérifier-létat-du-service), [Certificat](#certificat-tls) |
| Tous les 2–3 mois | Mettre à jour Certbot et le plugin OVH (pip), simuler un renouvellement | [Certbot](#certbot) |
| Après une mise à jour majeure de Debian (13 → 14) | **Recréer** l'environnement Python de Certbot | [Certbot](#après-une-mise-à-jour-majeure-de-debian) |
| Après un reset/changement de box | Vérifier la redirection du port 25 et l'IP publique | [Serveur à la maison](#serveur-à-la-maison) |
| En cas de doute | Envoyer un vrai mail de test ([Partie 3](03-test.md)) | — |

---

## Système Debian

```bash
sudo apt update && sudo apt upgrade
```

Pour que les correctifs de sécurité s'installent tout seuls :

```bash
sudo apt install -y unattended-upgrades
sudo dpkg-reconfigure -plow unattended-upgrades     # répondre « Oui »
```

> ℹ️ `apt upgrade` met à jour Podman, mais **ni l'image Mailpit, ni Certbot** (installé par pip) : voir ci-dessous.

## Mailpit (image du conteneur)

**Automatique** (recommandé) : grâce à `AutoUpdate=registry` dans le fichier `.container`, un timer Podman vérifie chaque jour si une nouvelle image existe, la télécharge et redémarre le service. Si le nouveau conteneur ne démarre pas, Podman revient à l'ancienne image.

```bash
sudo systemctl enable --now podman-auto-update.timer    # une seule fois
systemctl list-timers | grep podman-auto-update          # prochaine exécution
```

**Manuel** :

```bash
sudo podman auto-update --dry-run     # y a-t-il une mise à jour ?
sudo podman auto-update               # l'appliquer
```

Version actuellement lancée :

```bash
sudo podman exec mailpit /mailpit version
```

Faire de la place en supprimant les anciennes images :

```bash
sudo podman image prune -f
```

> ⚠️ L'image utilisée est `latest` : une nouvelle version majeure de Mailpit peut changer des options. En cas de souci après une mise à jour, lisez `sudo journalctl -u mailpit -n 50` et les notes de version sur https://github.com/axllent/mailpit/releases.

## Certbot

Certbot et le plugin OVH sont installés par pip dans `/opt/certbot` ([Partie 4, étape 1](04-tls.md#étape-1--installer-certbot-et-le-plugin-ovh)). `apt upgrade` ne les met **pas** à jour.

**Mettre à jour** :

```bash
sudo /opt/certbot/bin/pip install --upgrade pip
sudo /opt/certbot/bin/pip install --upgrade certbot certbot-dns-ovh
sudo certbot --version
```

**Vérifier que le renouvellement fonctionne toujours** (simulation : ne change rien, mais passe par l'API OVH) :

```bash
sudo certbot renew --dry-run
systemctl list-timers | grep certbot-renew               # prochaine exécution
sudo journalctl -u certbot-renew -n 30                   # résultat des derniers passages
```

Un `--dry-run` qui échoue avec une erreur `403` ou « Invalid credentials » veut presque toujours dire que le **jeton OVH** n'est plus valide : voir [Jeton d'API OVH](#jeton-dapi-ovh).

### Après une mise à jour majeure de Debian

Un passage à la version suivante de Debian change la version de Python : l'environnement `/opt/certbot` cesse alors de fonctionner (`certbot: No such file or directory` ou erreurs d'import). Recréez-le. Les certificats et la configuration dans `/etc/letsencrypt` sont conservés :

```bash
sudo rm -rf /opt/certbot
sudo python3 -m venv /opt/certbot
sudo /opt/certbot/bin/pip install --upgrade pip
sudo /opt/certbot/bin/pip install certbot certbot-dns-ovh
sudo certbot renew --dry-run
```

## Certificat TLS

Let's Encrypt **n'envoie plus d'e-mails d'avertissement** avant l'expiration (fin de ce service en 2025). C'est à vous de vérifier que le renouvellement a bien eu lieu.

Date d'expiration selon Certbot :

```bash
sudo certbot certificates
```

Date d'expiration du certificat **réellement présenté** par Mailpit (c'est celle qui compte) :

```bash
openssl s_client -starttls smtp -connect 127.0.0.1:25 -servername mail.mondomaine.fr </dev/null 2>/dev/null \
  | openssl x509 -noout -enddate
```

Les deux dates doivent être identiques et situées à plus de 30 jours. Si Certbot a renouvelé mais que Mailpit présente encore l'ancien certificat, le deploy hook n'a pas tourné. Relancez-le à la main :

```bash
sudo RENEWED_LINEAGE=/etc/letsencrypt/live/mail.mondomaine.fr /usr/local/bin/mailpit-cert-deploy.sh
```

## Jeton d'API OVH

Le jeton créé en [Partie 4, étape 3](04-tls.md#étape-3--obtenir-le-certificat-validation-dns-via-lapi-ovh) sert à **chaque** renouvellement.

- Il doit avoir une validité **illimitée**. S'il expire ou est supprimé, les renouvellements échouent sans bruit, et le certificat expire environ 30 jours plus tard.
- Pour le remplacer : créez un nouveau jeton (mêmes droits), mettez à jour `/root/.secrets/ovh.ini`, testez avec `sudo certbot renew --dry-run`, puis révoquez l'ancien depuis la console API OVH (`GET /me/api/credential`, puis `DELETE /me/api/credential/{credentialId}`).
- Vérifiez que le fichier est toujours protégé : `sudo ls -l /root/.secrets/ovh.ini` doit afficher `-rw-------`.

## Vérifier l'état du service

```bash
systemctl status mailpit --no-pager                      # "active (running)"
sudo podman ps                                           # conteneur "Up"
sudo journalctl -u mailpit --since "7 days ago" -p warning --no-pager | tail -20
```

L'interface web ne doit écouter **que** sur `127.0.0.1` :

```bash
sudo ss -ltnp | grep -E ':(25|8025) '
# attendu : 0.0.0.0:25 et 127.0.0.1:8025 (jamais 0.0.0.0:8025)
```

Le pare-feu (si ufw est actif) doit toujours contenir la règle de routage :

```bash
sudo ufw status | grep 1025                              # 10.88.0.0/16 1025/tcp  ALLOW FWD
```

Test d'envoi local rapide :

```bash
swaks --server 127.0.0.1 --port 25 --to essai@test.mondomaine.fr --tls
```

## Données et sauvegardes

La rétention est automatique : `MP_MAX_MESSAGES` (les 1000 derniers mails) et `MP_MAX_AGE` (7 jours, [Partie 1, étape 3](01-podman.md#étape-3--créer-le-fichier-de-configuration-de-mailpit)) suppriment les plus anciens mails.

Avec le relais de la [Partie 7](07-relay.md), l'API applique les mêmes règles, base **et** pièces jointes : 1000 mails au plus (`webhook.max_messages`), supprimés 7 jours après leur réception par Mailpit (`webhook.max_age_days`). Gardez les mêmes valeurs des deux côtés : une installation faite avec une version précédente de ce tutoriel a `MP_MAX_AGE=30d`, à passer à `7d` (`sudo nano /etc/mailpit/mailpit.env`, puis `sudo systemctl restart mailpit`). Rien à planifier : l'API fait ce nettoyage elle-même, au plus toutes les 15 minutes, après une requête du relais. Elle y retire aussi les fichiers laissés par un envoi interrompu, et les dossiers de mails déjà supprimés de la base.

> 💡 **Relais arrêté longtemps** : sans requête du relais, l'API ne nettoie pas. Lancez `php bin/cleanup.php` dans son dossier (SSH), ou créez une tâche planifiée quotidienne dans l'espace client OVH (voir « Rétention » dans le [README de l'API](../../api/README.md#rétention--7-jours-et-1000-mails-au-plus)). Chaque nettoyage est résumé dans `var/log/api.log` (`[maintenance] …`).

Place occupée par les mails :

```bash
sudo du -sh "$(sudo podman volume inspect mailpit-data --format '{{.Mountpoint}}')"
```

Vider tous les mails (curl demande le mot de passe) :

```bash
curl -s -u admin -X DELETE http://127.0.0.1:8025/api/v1/messages
```

**Sauvegarde** (facultatif : ce sont des mails de test). À garder hors du serveur, et à protéger, car les fichiers contiennent les mails et la configuration :

```bash
sudo podman volume export mailpit-data -o mailpit-data.tar
sudo tar czf mailpit-config.tar.gz /etc/mailpit /etc/containers/systemd/mailpit.container \
  /usr/local/bin/mailpit-cert-deploy.sh /etc/letsencrypt /root/.secrets/ovh.ini
```

Restauration du volume : `sudo systemctl stop mailpit`, `sudo podman volume import mailpit-data mailpit-data.tar`, `sudo systemctl start mailpit`.

## Changer le mot de passe de l'interface web

```bash
sudo nano /etc/mailpit/mailpit.env       # modifier MP_UI_AUTH
sudo systemctl restart mailpit
```

Si vous utilisez le relais de la [Partie 7](07-relay.md), reportez le nouveau mot de passe dans `RELAY_MAILPIT_AUTH` (`/etc/mailpit/relay.env`), puis lancez `sudo systemctl restart mailpit-relay`.

## Serveur à la maison

Si le serveur est derrière une box ([Partie 1, étape 6](01-podman.md#serveur-à-la-maison-derrière-une-box)) :

- **Après un reset ou un changement de box** : la redirection du port 25 et le bail DHCP statique sont souvent perdus. Recréez-les.
- **Si l'IP publique change** (changement d'offre, de box, de fournisseur d'accès) : mettez à jour l'enregistrement A dans la zone OVH ([Partie 2, étape 2](02-ovh-dns-zone.md#étape-2--ajouter-lenregistrement-a)), puis vérifiez avec `dig +short A mail.mondomaine.fr @1.1.1.1`.
- Vérifiez que la machine ne s'est pas mise en veille (portable : désactivez la veille sur fermeture du capot dans `/etc/systemd/logind.conf`, `HandleLidSwitch=ignore`).

---

[←](04-tls.md) · [Sommaire](../mailpit.md) · [→](06-webhook.md)
