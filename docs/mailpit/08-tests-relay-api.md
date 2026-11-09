# Partie 8 — Tester le relais et l'API, pas à pas

> Remplacez les valeurs d'exemple (`mondomaine.fr`, `admin:MotDePasseSolide`…) comme indiqué dans [Avant de commencer](../mailpit.md#avant-de-commencer). `api.mondomaine.fr` désigne l'adresse de l'[API](../../api/README.md) sur l'hébergement Web Cloud OVH.

Cette page enchaîne les tests dans l'ordre : d'abord l'API seule, puis le relais, puis le trajet complet, et enfin les pannes. **Ne passez à une étape que si la précédente donne le résultat attendu.** En cas d'écart, notez-le dans le [journal des tests](#journal-des-tests) en bas de page.

Chaque étape indique **où** la faire :

| Repère | Où |
|---|---|
| 🖥️ **Serveur** | le serveur Mailpit, à la maison (en SSH) |
| ☁️ **OVH** | l'espace client OVH, le FTP ou phpMyAdmin de l'hébergement Web Cloud |
| 💻 **Ordinateur** | votre poste, **en dehors** du réseau du serveur (le partage de connexion d'un téléphone convient) |

```
 🖥️ serveur maison                                                ☁️ Web Cloud OVH
┌──────────────────────────────────────────────┐
│ Mailpit ── webhook ──► mailpit-relay ────────┼── HTTPS + jeton ──► api.mondomaine.fr ──► MySQL
│    ▲                        │                │                         (phases 1-2)       + var/messages/
│    └── lit le mail, tag ────┘ (phase 3)      │
└──────────────────────────────────────────────┘
                     phases 4-5 : le trajet complet, puis les pannes
```

## Suivi

- [x] [Phase 0](#phase-0--point-de-départ) — Point de départ : Mailpit reçoit des mails
- [x] [Phase 1](#phase-1--lapi-est-en-ligne) — L'API est en ligne et protégée
- [x] [Phase 2](#phase-2--lapi-répond-au-serveur-mailpit) — L'API répond au serveur Mailpit
- [ ] [Phase 3](#phase-3--le-relais-démarre) — Le relais démarre
- [ ] [Phase 4](#phase-4--le-trajet-complet) — Le trajet complet : un mail arrive dans l'API
- [ ] [Phase 5](#phase-5--les-pannes) — Les pannes : aucun mail perdu
- [ ] [Phase 6](#phase-6--remise-en-ordre) — Remise en ordre

---

## Phase 0 — Point de départ

### Étape 0.1 — 🖥️ Serveur : Mailpit tourne et reçoit

```bash
sudo systemctl status mailpit --no-pager | head -5
swaks --server 127.0.0.1 --port 25 --to essai@test.mondomaine.fr --header "Subject: Phase 0"
curl -s -u admin:MotDePasseSolide http://127.0.0.1:8025/api/v1/message/latest | grep -o '"Subject":"[^"]*"'
```

✅ Attendu : `active (running)`, puis `"Subject":"Phase 0"`.

❌ Sinon : reprenez la [Partie 1](01-podman.md#dépannage). Inutile d'aller plus loin tant que ce test ne passe pas.

### Étape 0.2 — 🖥️ Serveur : noter les adresses IP du serveur

L'API n'accepte que les IP listées dans `webhook.allowed_ips`. Relevez celles que le serveur utilise pour sortir sur Internet :

```bash
curl -4 -s https://ifconfig.me; echo
```

```bash
curl -6 -s https://ifconfig.me; echo
```

Notez les deux résultats. Si la seconde commande n'affiche rien, le serveur n'a pas d'IPv6 : seule l'IPv4 compte.

> ⚠️ **Serveur à la maison :** l'IPv4 doit être **fixe** (option de votre box ou de votre fournisseur). Sinon, l'API refusera le serveur dès que l'IP changera.

**Choix retenu : autoriser uniquement l'IPv4.** L'IPv6 affichée ici est souvent une **adresse temporaire** : Linux en tire une nouvelle au hasard, en général chaque jour (« privacy extensions »). On la reconnaît à sa seconde moitié sans motif particulier, par exemple `…:99e9:63d0:137c:4512`. L'autoriser telle quelle ne tiendrait qu'un jour. On met donc **seulement l'IPv4** dans `allowed_ips`, avec deux conséquences :

- l'hébergement OVH répond aussi en IPv6, donc tous les `curl` de cette page vers l'API forcent l'IPv4 avec **`curl -4`**. Sans ce `-4`, la réponse serait `403` ;
- le relais n'est pas concerné : son réseau Podman n'utilise que l'IPv4 (vérifié à l'étape 3.4).

> Pour autoriser malgré tout l'IPv6, il faut la plage `/64` du réseau (les 4 premiers groupes suivis de `::/64`, par exemple `2001:db8:1:2::/64`), jamais l'adresse exacte.

---

## Phase 1 — L'API est en ligne

Le détail de chaque action est dans le [README de l'API](../../api/README.md#installation). Ici, on vérifie le résultat.

### Étape 1.1 — 💻 Ordinateur : préparer les fichiers

```bash
cd api
composer install --no-dev --optimize-autoloader
cp config.example.php config.php
openssl rand -hex 32
```

Dans `config.php`, renseignez :

- `db` : les identifiants de la base MySQL ;
- `webhook.allowed_ips` : l'IPv4 de l'étape 0.2, seule ;
- `webhook.token` : le résultat de `openssl rand -hex 32`.

Ce jeton servira à nouveau en phase 2 et en phase 3. Conservez-le dans un gestionnaire de mots de passe.

### Étape 1.2 — ☁️ OVH : base, fichiers, domaine

1. **phpMyAdmin** → onglet **Importer** → `sql/schema.sql`. Les tables `mailpit_messages` et `mailpit_parts` doivent apparaître.
2. **FTP** : envoyez tout le contenu de `api/` dans un dossier `api` à la racine de l'hébergement, **fichiers cachés compris** (`.htaccess`, `public/.htaccess`, `var/.htaccess`, `.ovhconfig`), ainsi que `vendor/` et `config.php`.
3. **Multisite** : ajoutez `api.mondomaine.fr`, avec `api/public` comme dossier racine, et cochez **SSL**.
4. **Version de PHP** : avec `api/public` comme dossier racine, déplacez `.ovhconfig` dans `api/public/`. Vérifiez ensuite la version active dans l'onglet **Informations générales** (8.4 minimum).

### Étape 1.3 — 💻 Ordinateur : l'API répond, et refuse les inconnus

Depuis votre ordinateur, dont l'IP n'est **pas** autorisée :

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://api.mondomaine.fr/v1/messages
```

✅ Attendu : `403`. Ce seul test vérifie quatre choses à la fois : le DNS, le certificat SSL, le chargement de PHP, de `vendor/` et de `config.php`, et le filtre IP.

| Résultat | Signification |
|---|---|
| `403` | ✅ Tout va bien |
| `000` + erreur SSL (relancez sans `-s -o /dev/null` pour la voir) | Certificat pas encore généré : attendez, ou regénérez-le dans l'espace client |
| `404` | Mauvais dossier racine du multisite, ou `.htaccess` non envoyés |
| `500` | `vendor/` ou `config.php` absent, ou `config.php` invalide. Le détail est dans `api/var/log/api.log` (FTP) |
| `200` | ⚠️ Le filtre IP ne fonctionne pas. Votre IP est-elle par erreur dans `allowed_ips` ? |

### Étape 1.4 — 🖥️ Serveur : les fichiers sensibles sont inaccessibles

Faites ce test **depuis le serveur Mailpit**, et non depuis votre ordinateur. Depuis une IP non autorisée, le filtre IP de l'API répond `403` à toutes les URL. Ce `403` cacherait un fichier qu'Apache servirait directement, sans passer par PHP. Depuis le serveur, le filtre laisse passer : on voit donc si les fichiers sont **vraiment** protégés.

```bash
for p in config.php .ovhconfig .htaccess composer.json composer.lock vendor/autoload.php src/Kernel.php sql/schema.sql var/log/api.log public/.htaccess; do
  printf '%-22s ' "$p"; curl -4 -s -o /dev/null -w '%{http_code}\n' "https://api.mondomaine.fr/$p"
done
```

✅ Attendu : `403` ou `404` sur chaque ligne. **Aucun `200`.**

❌ Un `200` : le fichier est lisible depuis Internet. Vérifiez que les `.htaccess` sont bien sur l'hébergement, et que le dossier racine du multisite est `api/public` (ou `api`, mais jamais le dossier qui contient l'hébergement entier).

---

## Phase 2 — L'API répond au serveur Mailpit

Toutes les commandes de cette phase se tapent sur 🖥️ le **serveur**, la seule machine autorisée.

### Étape 2.1 — 🖥️ Serveur : charger le jeton dans le terminal

Pour ne pas laisser le jeton dans l'historique du shell, saisissez-le ainsi (rien ne s'affiche pendant la saisie) :

```bash
read -rsp 'Jeton : ' JETON; echo
API=https://api.mondomaine.fr
```

Les commandes suivantes utilisent `$JETON` et `$API`. Si vous ouvrez un nouveau terminal, refaites cette étape.

### Étape 2.2 — 🖥️ Serveur : le jeton est obligatoire

```bash
# Sans jeton
curl -4 -s -o /dev/null -w '%{http_code}\n' "$API/v1/messages"

# Avec un faux jeton
curl -4 -s -o /dev/null -w '%{http_code}\n' -H 'Authorization: Bearer faux' "$API/v1/messages"

# Avec le bon jeton
curl -4 -s -w '\n%{http_code}\n' -H "Authorization: Bearer $JETON" "$API/v1/messages"
```

✅ Attendu : `401`, `401`, puis `{"total":0,…}` et `200`.

❌ `403` partout : l'IP du serveur n'est pas dans `allowed_ips`, ou le `-4` a été oublié. L'IP refusée est écrite dans `api/var/log/api.log` (`[ip-filter] requête refusée depuis « … »`).

❌ `401` même avec le bon jeton : l'en-tête `Authorization` ne parvient pas à PHP (`.htaccess` manquants), ou le jeton ne correspond pas à celui de `config.php`.

### Étape 2.3 — 🖥️ Serveur : IPv4 acceptée, IPv6 refusée

```bash
curl -4 -s -o /dev/null -w 'IPv4 : %{http_code}\n' -H "Authorization: Bearer $JETON" "$API/v1/messages"
curl -6 -s -o /dev/null -w 'IPv6 : %{http_code}\n' -H "Authorization: Bearer $JETON" "$API/v1/messages"
```

✅ Attendu (seule l'IPv4 est autorisée, voir l'étape 0.2) : `IPv4 : 200`, puis `IPv6 : 403`, ou `IPv6 : 000` si le serveur n'a pas d'IPv6. Le `403` en IPv6 prouve que le filtre IP fait son travail.

❌ `IPv4 : 403` : l'IPv4 de l'étape 0.2 n'est pas (ou mal) écrite dans `allowed_ips`.

### Étape 2.4 — 🖥️ Serveur : enregistrer un faux mail avec une pièce jointe

On envoie à la main ce que fera le relais : le mail en JSON, puis sa pièce jointe.

```bash
curl -4 -s -w '\n%{http_code}\n' -X POST "$API/v1/messages" \
  -H "Authorization: Bearer $JETON" -H 'Content-Type: application/json' \
  -d '{"ID":"test-manuel-1","Created":"2026-01-01T12:00:00Z","Subject":"Test manuel","From":{"Name":"","Address":"moi@exemple.fr"},"To":[],"Text":"Bonjour","Parts":[{"PartID":"2","Kind":"attachment","FileName":"test.txt","ContentType":"text/plain","ContentID":"","Size":5}]}'
```

✅ Attendu : `{"id":"test-manuel-1","status":"created","complete":false,"missing":["2"],"too_large":[]}` puis `201`.

Envoyez la pièce attendue (`missing`) :

```bash
printf 'hello' > /tmp/test.txt
curl -4 -s -w '\n%{http_code}\n' -X POST "$API/v1/messages/test-manuel-1/part/2" \
  -H "Authorization: Bearer $JETON" -H 'Content-Type: application/octet-stream' \
  -H "X-Content-SHA256: $(sha256sum /tmp/test.txt | cut -d' ' -f1)" \
  --data-binary @/tmp/test.txt
```

✅ Attendu : `"status":"stored"`, `"complete":true`, puis `201`.

Renvoyez le **même** mail (relancez la première commande `curl` de cette étape) :

✅ Attendu : `"status":"duplicate"` et `200`. L'API reconnaît le doublon : c'est ce qui permet au relais de renvoyer un mail sans risque.

### Étape 2.5 — ☁️ OVH : vérifier ce qui a été enregistré

- **phpMyAdmin**, table `mailpit_messages` : une ligne `test-manuel-1`, avec `text_body` = `Bonjour` et `completed_at` rempli.
- Table `mailpit_parts` : une ligne `test.txt` à l'état `stored`.
- **FTP** : le fichier `api/var/messages/test-manuel-1/part-2` contient `hello`.

Ensuite, nettoyez :

1. Dans phpMyAdmin, supprimez la ligne `test-manuel-1` de `mailpit_messages`. Sa pièce dans `mailpit_parts` est supprimée avec elle.
2. Par FTP, supprimez le dossier `var/messages/test-manuel-1/`.

### Étape 2.6 — 🖥️ Serveur : une pièce de 5 Mo passe (facultatif mais conseillé)

Ce test vérifie que l'hébergement accepte des requêtes de la taille maximale prévue :

```bash
head -c 5242880 /dev/urandom > /tmp/gros.bin
curl -4 -s -o /dev/null -w '%{http_code}\n' -X POST "$API/v1/messages" \
  -H "Authorization: Bearer $JETON" -H 'Content-Type: application/json' \
  -d '{"ID":"test-manuel-5mo","Created":"2026-01-01T12:00:00Z","Subject":"Test 5 Mo","From":{"Name":"","Address":"moi@exemple.fr"},"To":[],"Parts":[{"PartID":"2","Kind":"attachment","FileName":"gros.bin","ContentType":"application/octet-stream","ContentID":"","Size":5242880}]}'
curl -4 -s -w '\n%{http_code}\n' -X POST "$API/v1/messages/test-manuel-5mo/part/2" \
  -H "Authorization: Bearer $JETON" -H 'Content-Type: application/octet-stream' \
  -H "X-Content-SHA256: $(sha256sum /tmp/gros.bin | cut -d' ' -f1)" \
  --data-binary @/tmp/gros.bin
```

✅ Attendu : `201`, puis `"complete":true` et `201`.

Nettoyez ensuite comme à l'étape 2.5 (`test-manuel-5mo`), et supprimez le fichier local : `rm /tmp/test.txt /tmp/gros.bin`.

---

## Phase 3 — Le relais démarre

### Étape 3.1 — 🖥️ Serveur : installer le relais

Suivez la [Partie 7, étapes 1 à 8](07-relay.md#étape-1--installer-les-outils), avec deux précautions pour les tests :

- dans `relay.env`, mettez `RELAY_API_TOKEN` = le jeton de la phase 1 ;
- ajoutez `RELAY_QUERY=after:AAAA-MM-JJ`, avec **la date du jour**. Sans cette ligne, le relais enverrait à l'API **tous** les mails déjà présents dans Mailpit, y compris ceux des tests précédents.

### Étape 3.2 — 🖥️ Serveur : le relais a démarré avec la bonne configuration

```bash
sudo systemctl status mailpit mailpit-relay --no-pager | grep -E '●|Active'
sudo journalctl -u mailpit-relay -n 20 --no-pager
```

✅ Attendu : les deux services `active (running)`, et une ligne de ce type :

```
[relay] INFO  démarrage : écoute sur 0.0.0.0:8080, Mailpit=http://mailpit:8025 (auth oui), API=https://api.mondomaine.fr (jeton 3f9a…), recherche « !tag:relayed !tag:relay-failed after:2026-11-09 » toutes les 30 s
```

Vérifiez dans cette ligne : l'adresse de l'API, `auth oui`, les 4 premiers caractères du jeton, et la date de `after:`.

❌ `configuration invalide : …` : le message indique la variable fautive dans `relay.env`.

### Étape 3.3 — 🖥️ Serveur : le relais voit Mailpit, et Mailpit voit le relais

```bash
# Le relais répond (depuis l'intérieur de son conteneur)
sudo podman exec mailpit-relay php -r 'echo file_get_contents("http://127.0.0.1:8080/health"), PHP_EOL;'

# Le relais trouve Mailpit par son nom
sudo podman exec mailpit-relay php -r 'echo gethostbyname("mailpit"), PHP_EOL;'

# Les deux conteneurs sont sur le réseau « mailpit »…
sudo podman inspect -f '{{.Name}} : {{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' mailpit mailpit-relay

# … et ce réseau a la résolution de noms
sudo podman network inspect mailpit | grep dns_enabled
```

✅ Attendu :

- `{"status":"ok"}` ;
- une IP en `10.x.x.x`, et non le mot `mailpit` renvoyé tel quel ;
- `mailpit : mailpit` et `mailpit-relay : mailpit` ;
- `"dns_enabled": true`.

### Étape 3.4 — 🖥️ Serveur : l'IP que voit l'API quand c'est le relais qui appelle

Le relais sort sur Internet **depuis son conteneur**, pas depuis le serveur. Le réseau Podman `mailpit` n'a en général que l'IPv4. Le relais appelle donc l'API en **IPv4**, comme les tests `curl -4` de la phase 2.

```bash
sudo podman exec mailpit-relay php -r 'echo file_get_contents("https://ifconfig.me/ip"), PHP_EOL;'
```

✅ Attendu : une IP présente dans `allowed_ips`. C'est normalement l'IPv4 de l'étape 0.2.

❌ Une autre IP : ajoutez-la dans `allowed_ips` (`config.php`, envoi FTP).

### Étape 3.5 — 🖥️ Serveur : rien d'exposé

```bash
sudo podman port mailpit-relay
sudo ss -ltnp | grep -E ':8080 ' || echo "port 8080 non publié : OK"
```

✅ Attendu : aucune sortie pour la première commande, puis `port 8080 non publié : OK`.

---

## Phase 4 — Le trajet complet

Gardez un **premier terminal** ouvert sur le serveur avec les logs du relais pendant toute la phase :

```bash
sudo journalctl -u mailpit-relay -f
```

Tapez les commandes dans un **second terminal**, après y avoir refait l'étape 2.1 (chargement du jeton).

### Étape 4.1 — 🖥️ Serveur : un mail simple

```bash
swaks --server 127.0.0.1 --port 25 --to essai@test.mondomaine.fr --header "Subject: Phase 4.1"
```

✅ Attendu dans le premier terminal, en une ou deux secondes :

```
[relay] INFO  mail Wdyx4jVvBWELmoNePrh8Ff enregistré par l'API (Phase 4.1), 0 pièce(s) envoyée(s), essai 1
```

Vérifiez ensuite chaque côté :

```bash
# Côté API : le mail est là, complet
curl -4 -s -H "Authorization: Bearer $JETON" "$API/v1/messages?limit=1" | grep -oE '"(subject|complete)":[^,]*'

# Côté Mailpit : le mail porte le tag « relayed » et reste non lu
curl -s -u admin:MotDePasseSolide http://127.0.0.1:8025/api/v1/messages?limit=1 | grep -oE '"(Read|Tags)":[^,]*'
```

✅ Attendu : `"subject":"Phase 4.1"` et `"complete":true`, puis `"Read":false` et `"Tags":["relayed"]`.

❌ Le mail arrive au bout de 30 s au lieu d'une ou deux secondes : le webhook n'atteint pas le relais, et c'est la recherche périodique qui l'a trouvé. Regardez `sudo journalctl -u mailpit -n 20 | grep webhook` et la [Partie 7, étape 7](07-relay.md#étape-7--envoyer-le-webhook-de-mailpit-au-relais).

### Étape 4.2 — 🖥️ Serveur : HTML, image intégrée et pièce jointe

Ce script envoie un mail complet : texte, HTML avec une image intégrée (`cid:`) et une pièce jointe.

```bash
python3 - <<'EOF'
import base64, smtplib
from email.message import EmailMessage
from email.utils import make_msgid

png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==")
cid = make_msgid(domain="test.local")

msg = EmailMessage()
msg["From"] = "Test relais <test@exemple.fr>"
msg["To"] = "essai@test.mondomaine.fr"
msg["Subject"] = "Phase 4.2 : HTML + image + PJ"
msg.set_content("Version texte du mail de test.")
msg.add_alternative(f'<p>Version <b>HTML</b> avec une image : <img src="cid:{cid[1:-1]}"></p>', subtype="html")
msg.get_payload()[1].add_related(png, "image", "png", cid=cid, filename="point.png", disposition="inline")
msg.add_attachment("Bonjour, je suis une pièce jointe.\n".encode(), maintype="text", subtype="plain", filename="note.txt")

with smtplib.SMTP("127.0.0.1", 25) as s:
    s.send_message(msg)
print("envoyé")
EOF
```

✅ Attendu dans les logs : `… enregistré par l'API (Phase 4.2 : HTML + image + PJ), 2 pièce(s) envoyée(s), essai 1`.

Côté API :

```bash
curl -4 -s -H "Authorization: Bearer $JETON" "$API/v1/messages?limit=1" | python3 -m json.tool | grep -E '"(subject|complete|kind|file_name|status)"'
```

✅ Attendu : `"complete": true`, une pièce `"kind": "inline"` (`point.png`) et une pièce `"kind": "attachment"` (`note.txt`), toutes deux `"status": "stored"`.

☁️ **OVH** : dans phpMyAdmin, la ligne du mail a `html_body` et `headers` remplis. Par FTP, `var/messages/<ID>/` contient deux fichiers `part-…`.

### Étape 4.3 — 🖥️ Serveur : une pièce jointe trop grosse

```bash
head -c 6000000 /dev/urandom > /tmp/gros.bin
swaks --server 127.0.0.1 --port 25 --to essai@test.mondomaine.fr --header "Subject: Phase 4.3" --attach @/tmp/gros.bin
rm /tmp/gros.bin
```

✅ Attendu dans les logs :

- `… enregistré par l'API (Phase 4.3), 0 pièce(s) envoyée(s)` ;
- puis l'avertissement `pièce … « gros.bin » (6000000 octets) au-delà de la limite de l'API, seule sa description est enregistrée`.

Le mail est quand même livré et tagué `relayed`. Seul le contenu de la pièce manque (état `too_large`).

### Étape 4.4 — 🖥️ Serveur : une rafale de 10 mails

Ce test vérifie qu'aucun mail n'est ignoré quand ils arrivent dans la même seconde (`MP_WEBHOOK_LIMIT=0`), et que le relais les envoie un par un.

```bash
AVANT=$(curl -4 -s -H "Authorization: Bearer $JETON" "$API/v1/messages?limit=1" | grep -o '"total":[0-9]*')
for i in $(seq 1 10); do
  swaks --server 127.0.0.1 --port 25 --to essai@test.mondomaine.fr --header "Subject: Rafale $i" >/dev/null
done
```

Attendez que les logs affichent 10 lignes `enregistré par l'API (Rafale …)`, puis :

```bash
echo "avant : $AVANT"
curl -4 -s -H "Authorization: Bearer $JETON" "$API/v1/messages?limit=1" | grep -o '"total":[0-9]*'
sudo podman exec mailpit-relay php /app/bin/status.php
```

✅ Attendu : un `total` augmenté de 10, et `status.php` qui affiche **0** mail en attente et **0** refusé.

### Étape 4.5 — 💻 Un vrai mail depuis Internet

Depuis Gmail (ou une autre messagerie), envoyez à `nimportequoi@test.mondomaine.fr` un mail avec une pièce jointe PDF ou une image.

✅ Attendu : la ligne `enregistré par l'API` dans les logs, puis le mail complet dans l'API, avec sa pièce `stored`.

---

## Phase 5 — Les pannes

Le relais est fait pour ne perdre **aucun** mail. Ces tests le vérifient en provoquant des pannes. Gardez les logs ouverts dans le premier terminal.

### Étape 5.1 — 🖥️ Serveur : le relais est arrêté

```bash
sudo systemctl stop mailpit-relay
for i in 1 2 3; do swaks --server 127.0.0.1 --port 25 --to essai@test.mondomaine.fr --header "Subject: Pendant l'arret $i" >/dev/null; done
sudo journalctl -u mailpit -n 5 --no-pager | grep -i webhook
sudo systemctl start mailpit-relay
```

✅ Attendu :

- pendant l'arrêt, Mailpit écrit des erreurs de webhook (`connection refused` ou `no such host`). C'est normal ;
- au redémarrage, les 3 mails `Pendant l'arret …` sont enregistrés par l'API en quelques secondes.

### Étape 5.2 — 🖥️ Serveur : l'API refuse le relais (mauvais jeton)

On simule un jeton désynchronisé, par exemple après avoir changé `config.php` sans mettre à jour le relais.

1. Dans `/etc/mailpit/relay.env`, modifiez **un seul caractère** de `RELAY_API_TOKEN`, puis :
   ```bash
   sudo systemctl restart mailpit-relay
   swaks --server 127.0.0.1 --port 25 --to essai@test.mondomaine.fr --header "Subject: Phase 5.2"
   ```
2. ✅ Attendu dans les logs :
   ```
   [relay] WARN  mail … : échec de l'essai 1 (API (message) : HTTP 401 …), nouvel essai dans 30 s
   ```
   puis l'essai 2 au bout de 30 s, l'essai 3 au bout d'une minute, etc. Le mail n'a **pas** le tag `relayed` dans Mailpit.
3. Remettez le bon jeton dans `relay.env`, puis :
   ```bash
   sudo systemctl restart mailpit-relay
   ```
4. ✅ Attendu : `mail … enregistré par l'API (Phase 5.2)` aussitôt après le redémarrage.

> 💡 La même chose se produit si l'API est en panne ou si l'IP est refusée (`HTTP 403`, `injoignable`). Le relais réessaie sans limite de durée : il suffit de corriger le problème.

### Étape 5.3 — 🖥️ Serveur : renvoyer un mail déjà relayé

Dans l'interface de Mailpit ([tunnel SSH](01-podman.md#étape-7--accéder-à-linterface-web)), ouvrez le mail `Phase 4.1` et **retirez** son tag `relayed`.

✅ Attendu :

- dans les logs, une nouvelle ligne `enregistré par l'API (Phase 4.1)` ;
- le tag `relayed` est de nouveau posé ;
- côté API, **pas** de deuxième ligne : le `total` n'augmente pas (doublon reconnu).

### Étape 5.4 — 🖥️ Serveur : redémarrage complet du serveur (facultatif)

```bash
sudo reboot
```

Après le redémarrage :

```bash
sudo systemctl status mailpit mailpit-relay --no-pager | grep -E '●|Active'
swaks --server 127.0.0.1 --port 25 --to essai@test.mondomaine.fr --header "Subject: Apres reboot"
sudo journalctl -u mailpit-relay -n 5 --no-pager
```

✅ Attendu : les deux services `active (running)` sans action de votre part, et le mail `Apres reboot` enregistré par l'API.

---

## Phase 6 — Remise en ordre

1. 🖥️ `sudo podman exec mailpit-relay php /app/bin/status.php` : **0** en attente, **0** refusé.
2. 🖥️ **`RELAY_QUERY`** : vous pouvez garder la ligne `after:…`. Elle empêche seulement de relayer les mails reçus **avant** cette date. Si vous la retirez, le relais enverra aussi ces anciens mails à l'API au prochain redémarrage.
3. ☁️ **Mails de test dans l'API** : supprimez-les si vous voulez une base propre. Supprimez les lignes dans `mailpit_messages` (phpMyAdmin) et les dossiers `var/messages/<ID>/` (FTP). La rétention à 1000 mails les fera de toute façon disparaître.
4. ☁️ **`config.php`** : vérifiez `'debug' => false`.
5. ☁️ **`var/log/api.log`** : relisez-le, puis videz-le. Il garde la trace des tests 401 et 403.
6. Cochez les phases dans le [suivi](#suivi) en haut de page.

---

## Journal des tests

Notez ici chaque écart constaté et sa correction, au fil des essais.

| Date | Étape | Constat | Correction |
|---|---|---|---|
| 2026-11-09 | 0.1 | Mailpit tourne, `swaks` → `250`, l'API locale renvoie `"Subject":"Phase 0"` | — |
| 2026-11-09 | 0.2 | IPv4 fixe. IPv6 = adresse temporaire (change régulièrement) | Seule l'IPv4 dans `allowed_ips`. Tous les `curl` vers l'API passent en `curl -4` |
| 2026-11-09 | 1.2 | `schema.sql` importé (seul fichier SQL depuis le retrait des upgrades), tables visibles, MySQL 8.4 | — |
| 2026-11-09 | 1.3 | `403` depuis l'ordinateur, `401` (sans jeton) depuis le serveur Mailpit | Étape 1.4 déplacée sur le serveur : depuis une IP refusée, le `403` du filtre IP masque les fichiers servis directement |
| 2026-11-09 | 1.4 | Depuis le serveur : `403` ou `404` sur les 10 chemins, aucun `200` | — |
| 2026-11-09 | 2.2 | Sans jeton `401`, faux jeton `401`, bon jeton `200` + liste vide (base joignable) | — |
| 2026-11-09 | 2.3 | IPv4 `200`, IPv6 `403`. L'API a une IPv6 (OVH), le serveur sait la joindre | Comportement voulu : seule l'IPv4 est autorisée |
| 2026-11-09 | 2.4 | Mail `201 created` (`missing: ["2"]`), pièce `201 stored` (`complete: true`), renvoi `200 duplicate` | — |
| 2026-11-09 | 2.5 | Lignes `mailpit_messages` / `mailpit_parts` et fichier `part-2` conformes. Nettoyage fait | — |
| 2026-11-09 | 2.6 | Pièce de 5 Mo : `201 stored`, `complete: true`, envoyée en 1 s | — |
| 2026-11-09 | 3.1 | Build de l'image : `DNS: transient error`. ufw est en `deny (routed)` : rien ne sort des conteneurs, même vers `1.1.1.1` | `ufw route allow from 10.88.0.0/16` et `from 10.89.0.0/16`. Partie 7 complétée : règles ufw (étape 1), sous-réseau fixe `10.89.25.0/24` et règle SMTP entrante (étape 4) |
| 2026-11-09 | 3.2 | Relais démarré, mais `Mailpit /api/v1/search : Resolving timed out` en boucle. `UFW BLOCK` des requêtes du relais vers `10.89.25.1:53` (DNS interne `aardvark-dns`, filtré comme trafic entrant) | `ufw allow` UDP et TCP 53 de `10.89.25.0/24` vers `10.89.25.1`. Ajouté à la Partie 7, étape 1 |

---

[←](07-relay.md) · [Sommaire](../mailpit.md)
