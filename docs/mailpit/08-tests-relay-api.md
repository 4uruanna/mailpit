# Partie 8 — Tester le relais et l'API, pas à pas

> Remplacez les valeurs d'exemple (`mondomaine.fr`, `admin:MotDePasseSolide`…) comme indiqué dans [Avant de commencer](../mailpit.md#avant-de-commencer). `api.mondomaine.fr` désigne l'adresse de l'[API](../../api/README.md) sur l'hébergement Web Cloud OVH.

Cette page enchaîne les tests dans l'ordre : d'abord l'API seule, puis le relais, puis le trajet complet, et enfin les pannes. **Ne passez à une étape que si la précédente donne le résultat attendu.** En cas d'écart, notez-le dans le [journal des tests](#journal-des-tests) en bas de page.

> 💡 Cette recette vérifie l'**installation réelle** (serveur, hébergement, DNS, `.htaccess`…). Le **code** de l'API et du relais a aussi des tests automatisés (PHPUnit), lancés par GitHub Actions à chaque `push` : voir les sections [Tests automatisés de l'API](../../api/README.md#tests-automatisés) et [Tests du relais](../../relay/README.md#tests).

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
- [x] [Phase 3](#phase-3--le-relais-démarre) — Le relais démarre
- [x] [Phase 4](#phase-4--le-trajet-complet) — Le trajet complet : un mail arrive dans l'API
- [x] [Phase 5](#phase-5--les-pannes) — Les pannes : aucun mail perdu
- [x] [Phase 6](#phase-6--remise-en-ordre) — Remise en ordre

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

1. **phpMyAdmin** → onglet **Importer** → `sql/schema.sql`. Les tables `mailpit_messages`, `mailpit_parts` et `mailpit_health_pings` (pings de santé du relais) doivent apparaître, avec celles du Dashboard. Base créée avec une version précédente : importez plutôt les migrations de `sql/migrations/` (voir le [README de l'API](../../api/README.md#2-créer-les-tables)).
2. **FTP** : envoyez tout le contenu de `api/` dans un dossier `api` à la racine de l'hébergement, **fichiers cachés compris** (`.htaccess`, `public/.htaccess`, `var/.htaccess`, `.ovhconfig`), ainsi que `vendor/` et `config.php`.
3. **Multisite** : ajoutez `api.mondomaine.fr`, avec `api/public` comme dossier racine, et cochez **SSL**. L'API impose HTTPS : attendez que la colonne **SSL** indique **Activé** avant l'étape 1.3.
4. **Version de PHP** : avec `api/public` comme dossier racine, déplacez `.ovhconfig` dans `api/public/`. Vérifiez ensuite la version active dans l'onglet **Informations générales** (8.4 minimum).

### Étape 1.3 — 💻 Ordinateur : l'API répond, et refuse les inconnus

Depuis votre ordinateur, dont l'IP n'est **pas** autorisée :

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://api.mondomaine.fr/v1/messages
```

✅ Attendu : `403`. Ce seul test vérifie quatre choses à la fois : le DNS, le certificat SSL, le chargement de PHP, de `vendor/` et de `config.php`, et le filtre IP.

| Résultat | Signification |
|---|---|
| `403` | ✅ Tout va bien |
| `000` + erreur SSL (relancez sans `-s -o /dev/null` pour la voir) | Certificat pas encore généré : attendez, ou regénérez-le dans l'espace client |
| `404` | Mauvais dossier racine du multisite, ou `.htaccess` non envoyés |
| `500` | `vendor/` ou `config.php` absent, ou `config.php` invalide. Le détail est dans `api/var/log/api.log` (FTP) |
| `401` | ⚠️ Le filtre IP ne fonctionne pas. Votre IP est-elle par erreur dans `allowed_ips` ? |

> ℹ️ Le filtre IP ne protège que les routes du **relais** (envoi des mails, `POST`). Les routes des jetons développeurs (`GET /v1/messages`, `/v1/addresses`…) et le Dashboard (`/v1/app/…`) sont ouverts à toutes les IP : sans jeton, `GET /v1/messages` répond donc `401`, et non `403`.

Les erreurs du Dashboard sont des pages HTML, celles de l'API restent en JSON :

```bash
curl -s -o /dev/null -w '%{http_code} %{content_type}\n' https://api.mondomaine.fr/v1/app/inconnue
curl -s -o /dev/null -w '%{http_code} %{content_type}\n' https://api.mondomaine.fr/v1/inconnue
```

✅ Attendu : `404 text/html; charset=utf-8`, puis `404 application/json`.

❌ `404 application/json` sur la première ligne : `src/` ou `templates/` sont d'une version précédente (renvoyez-les en FTP).

### Étape 1.4 — 🖥️ Serveur : les fichiers sensibles sont inaccessibles

Faites ce test **depuis le serveur Mailpit** (il fonctionne aussi depuis votre ordinateur, le filtre IP ne protégeant que les routes du relais). On vérifie que les fichiers sont **vraiment** protégés : un fichier qu'Apache servirait directement, sans passer par PHP, répondrait `200`.

```bash
for p in config.php .ovhconfig .htaccess composer.json composer.lock vendor/autoload.php src/Kernel.php sql/schema.sql var/log/api.log var/log/api.log.1 public/.htaccess templates/layout.php bin/create-admin.php bin/cleanup.php var/messages/.maintenance tests/bootstrap.php phpunit.xml.dist; do
  printf '%-26s ' "$p"; curl -4 -s -o /dev/null -w '%{http_code}\n' "https://api.mondomaine.fr/$p"
done
```

✅ Attendu : `403` ou `404` sur chaque ligne. **Aucun `200`.**

❌ Un `200` : le fichier est lisible depuis Internet. Vérifiez que les `.htaccess` sont bien sur l'hébergement, et que le dossier racine du multisite est `api/public` (ou `api`, mais jamais le dossier qui contient l'hébergement entier).

### Étape 1.5 — 💻 Ordinateur : HTTPS obligatoire

```bash
# Une page en http:// est redirigée vers https://
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' http://api.mondomaine.fr/v1/app/signin
# Un POST en http:// est refusé
curl -s -w '\n%{http_code}\n' -X POST http://api.mondomaine.fr/v1/messages
# En HTTPS, le navigateur est prié de ne plus jamais utiliser http://
curl -s -D - -o /dev/null https://api.mondomaine.fr/v1/app/signin | grep -i strict-transport
```

✅ Attendu :

```
301 https://api.mondomaine.fr/v1/app/signin
{"error":"HTTPS obligatoire : renvoyez la requête en https://"}
403
strict-transport-security: max-age=31536000
```

| Résultat | Signification |
|---|---|
| `200` au lieu de `301` | `.htaccess` pas à jour, et API pas à jour non plus (sinon PHP redirigerait) : renvoyez les fichiers en FTP. Vérifiez aussi que `force_https` n'est pas à `false` dans `config.php` |
| `301` en boucle dans un navigateur (« Trop de redirections ») | Un proxy ou un CDN est placé devant l'hébergement : voir [Derrière un proxy ou un CDN](../../api/README.md#derrière-un-proxy-ou-un-cdn) |
| Pas de ligne `strict-transport-security` | `.htaccess` ou API pas à jour |

### Étape 1.6 — 💻 Ordinateur : créer un administrateur du Dashboard

Le Dashboard (`https://api.mondomaine.fr/v1/app/signin`) a **ses propres comptes**, dans la table `mailpit_admins`, vide après l'import de `schema.sql`. Les identifiants de l'interface de Mailpit (`admin:MotDePasseSolide`, c'est-à-dire `MP_UI_AUTH`) n'y fonctionnent **pas**. Tant qu'aucun compte n'est créé, personne ne peut se connecter.

1. 💻 Dans le dossier `api/` (PHP 8.4 ou plus) :
   ```bash
   php bin/create-admin.php
   ```
   Choisissez un identifiant et un mot de passe (12 caractères minimum). Le script affiche une requête `INSERT INTO mailpit_admins …`, avec le mot de passe **haché**.
2. ☁️ **phpMyAdmin** → la base de `config.php` → onglet **SQL** : collez la requête, puis **Exécuter**.
3. 💻 Dans un navigateur, connectez-vous sur `https://api.mondomaine.fr/v1/app/signin` avec cet identifiant et ce mot de passe.

✅ Attendu : « 1 ligne insérée » dans phpMyAdmin, puis la page **Messages** du Dashboard.

| Résultat | Signification |
|---|---|
| « … Aucun administrateur n'existe encore … » | La requête n'a pas été exécutée, ou l'a été dans une autre base que celle de `config.php` (`db.name`) |
| « Identifiant ou mot de passe incorrect » seul | Le compte existe. La cause est dans `var/log/api.log` (FTP), à la fin de la ligne `[signin] échec pour « … »` : `identifiant inconnu`, `mot de passe incorrect`, ou mot de passe enregistré qui n'est pas un haché (saisi en clair, coupé au copier-coller…). Relancez `php bin/create-admin.php` avec le même identifiant : sa requête remplace le mot de passe |
| « Trop de tentatives… » | 3 échecs en 30 minutes pour cet identifiant depuis votre adresse. Pour réessayer tout de suite : `DELETE FROM mailpit_login_attempts WHERE username = 'identifiant';` dans phpMyAdmin |

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
  -d '{"ID":"test-manuel-1","Created":"'"$(date -u +%FT%TZ)"'","Subject":"Test manuel","From":{"Name":"","Address":"moi@exemple.fr"},"To":[],"Text":"Bonjour","Parts":[{"PartID":"2","Kind":"attachment","FileName":"test.txt","ContentType":"text/plain","ContentID":"","Size":5}]}'
```

✅ Attendu : `{"id":"test-manuel-1","status":"created","complete":false,"missing":["2"],"too_large":[]}` puis `201`.

💡 `Created` (réception par Mailpit) vaut l'heure actuelle (`date -u`) : un mail de plus de 7 jours serait supprimé par le nettoyage automatique de l'API dès cette requête (`webhook.max_age_days`), et l'envoi de sa pièce répondrait `404`.

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

Relisez-le par l'API. La liste n'en donne que le résumé ; le mail complet (corps, en-têtes) se lit par son ID :

```bash
# La liste : le résumé, sans le corps
curl -4 -s -H "Authorization: Bearer $JETON" "$API/v1/messages" | grep -oE '"(id|subject|text)":[^,]*'

# Le mail complet
curl -4 -s -H "Authorization: Bearer $JETON" "$API/v1/messages/test-manuel-1" | grep -oE '"(id|subject|text)":[^,]*'
```

✅ Attendu : `"id":"test-manuel-1"` et `"subject":"Test manuel"` pour la liste, **sans** `"text"` ; puis les mêmes et `"text":"Bonjour"` pour le mail complet.

❌ `405` sur le mail complet : l'API en ligne est une version précédente, sans `GET /v1/messages/{id}` (seul `DELETE` existe à cette adresse) : renvoyez `src/` en FTP.

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
  -d '{"ID":"test-manuel-5mo","Created":"'"$(date -u +%FT%TZ)"'","Subject":"Test 5 Mo","From":{"Name":"","Address":"moi@exemple.fr"},"To":[],"Parts":[{"PartID":"2","Kind":"attachment","FileName":"gros.bin","ContentType":"application/octet-stream","ContentID":"","Size":5242880}]}'
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
- ajoutez `RELAY_QUERY=after:AAAA-MM-JJ`, avec **la date du jour**. Sans cette ligne, le relais enverrait à l'API **tous** les mails déjà présents dans Mailpit, y compris ceux des tests précédents ;
- **si ufw est actif**, n'oubliez pas ses règles : sortie des conteneurs et DNS interne de Podman (Partie 7, étape 1), SMTP entrant sur le réseau `mailpit` (encadré de l'étape 4). Sans elles, la construction de l'image échoue (`DNS: transient error`), le relais ne trouve pas Mailpit (`Resolving timed out`), ou plus aucun mail n'arrive d'Internet.

### Étape 3.2 — 🖥️ Serveur : le relais a démarré avec la bonne configuration

```bash
sudo systemctl status mailpit mailpit-relay --no-pager | grep -E '●|Active'
sudo journalctl -u mailpit-relay -n 20 --no-pager
```

✅ Attendu : les deux services `active (running)`, et une ligne de ce type :

```
[relay] INFO  démarrage : écoute sur 0.0.0.0:8080, Mailpit=http://mailpit:8025 (auth oui), API=https://api.mondomaine.fr (jeton 3f9a…), recherche « !tag:relayed !tag:relay-failed after:2026-11-09 » toutes les 30 s, ping de santé toutes les 30 s
```

Vérifiez dans cette ligne : l'adresse de l'API, `auth oui`, les 4 premiers caractères du jeton, la date de `after:`, et `ping de santé toutes les 30 s`. Aucune ligne `ping de santé non enregistré` ne doit suivre.

❌ `ping de santé non enregistré : API : HTTP 404` : l'API en ligne est une version précédente, sans `POST /v1/health` (renvoyez `src/` et `templates/` en FTP). `HTTP 500` : la table `mailpit_health_pings` manque (étape 1.2).

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

### Étape 3.6 — 💻 Ordinateur : la page Statut montre « Connecté »

Le relais envoie un **ping de santé** à l'API toutes les 30 secondes. Ouvrez le Dashboard de l'API dans un navigateur, connectez-vous avec le compte créé à l'[étape 1.6](#étape-16---ordinateur--créer-un-administrateur-du-dashboard) (pas celui de Mailpit), puis cliquez sur **Statut** dans le menu (`https://api.mondomaine.fr/v1/app/status`).

✅ Attendu :

- le badge vert **Connecté**, et « dernier ping il y a » moins de 30 secondes ;
- **Dernier ping** à l'heure actuelle (fuseau du Dashboard), **Dernière latence** de quelques dizaines ou centaines de millisecondes (« inconnue » juste après le démarrage du relais : elle arrive avec le 2e ping) ;
- **Mails en attente** : `0`, et **Mails refusés** : `0` ;
- **Disponibilité** « depuis le premier ping », et dans le graphique, une première barre bleue à droite. La page se recharge seule chaque minute, sans prolonger votre session : laissée ouverte plus de 2 heures sans clic, elle revient à la page de connexion.

❌ « Aucun ping reçu » : regardez le journal du relais (`sudo journalctl -u mailpit-relay -n 20 --no-pager`, lignes `ping de santé …`) et l'étape 3.2.

❌ « inconnu » pour les mails en attente : le relais ne joint pas Mailpit (lignes `ping de santé envoyé sans les compteurs` dans son journal) : voir l'étape 3.3.

---

## Phase 4 — Le trajet complet

Gardez un **premier terminal** ouvert sur le serveur avec les logs du relais pendant toute la phase :

```bash
sudo journalctl -u mailpit-relay -f
```

Tapez les commandes dans un **second terminal**, après y avoir refait l'étape 2.1 (chargement du jeton). Enregistrez-y aussi l'adresse de test, avec **votre** sous-domaine, celui de `MP_SMTP_ALLOWED_RECIPIENTS` (`sudo grep '^MP_SMTP_ALLOWED' /etc/mailpit/mailpit.env`) :

```bash
export TO=essai@test.mondomaine.fr
```

Les commandes des phases 4 et 5 utilisent `$TO`. Refaites cet `export` dans chaque nouveau terminal. Si `TO` est vide, les commandes s'arrêtent avec le message `TO est vide`. Sans cette protection, `swaks` demanderait le destinataire (`To:`) et attendrait indéfiniment une saisie. Si l'adresse n'est pas acceptée par Mailpit, `swaks` se termine par une erreur `550` et aucun mail n'arrive : vérifiez `$TO` en premier (`echo $TO`).

### Étape 4.1 — 🖥️ Serveur : un mail simple

```bash
swaks --server 127.0.0.1 --port 25 --to "${TO:?TO est vide : export TO=essai@votre-sous-domaine}" --header "Subject: Phase 4.1"
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

### Étape 4.1 bis — 🖥️ Serveur : attendre l'arrivée d'un mail (`wait`)

Un développeur qui teste un envoi peut demander à l'API d'**attendre** le mail, au lieu de l'interroger en boucle ([Attendre un mail](../../api/README.md#attendre-un-mail--wait-et-since)) : il note l'heure, déclenche l'envoi, puis appelle `GET /v1/messages?address=…&since=<heure>&wait=60`. Ici, le mail part 5 secondes après le début de l'attente :

```bash
DEPUIS=$(date -u +%Y-%m-%dT%H:%M:%SZ)
(sleep 5; swaks --server 127.0.0.1 --port 25 --to "${TO:?TO est vide : export TO=essai@votre-sous-domaine}" --header "Subject: Phase 4.1 bis" >/dev/null) &
time curl -4 -s --max-time 70 -H "Authorization: Bearer $JETON" "$API/v1/messages?address=$TO&since=$DEPUIS&wait=60&limit=1" \
  | grep -oE '"(count|waited_ms|subject|complete)":[^,]*'
```

✅ Attendu, au bout de 6 à 8 secondes (`real` de `time`) : `"count":1`, `"waited_ms"` entre 5000 et 8000 environ, `"subject":"Phase 4.1 bis"` et `"complete":true`. La réponse arrive dès que le mail est complet dans l'API, sans attendre les 60 secondes.

Sans nouveau mail, la réponse arrive à la fin du délai, vide :

```bash
DEPUIS=$(date -u +%Y-%m-%dT%H:%M:%SZ)
time curl -4 -s --max-time 20 -H "Authorization: Bearer $JETON" "$API/v1/messages?address=$TO&since=$DEPUIS&wait=10" | grep -oE '"(count|waited_ms)":[^,]*'
```

✅ Attendu, au bout de 10 secondes environ : `"count":0` et `"waited_ms":10…`.

❌ Réponse immédiate, sans `"waited_ms"` : l'API en ligne est une version précédente, qui ignore `wait` (renvoyez `src/` en FTP). `"count":0` après 60 secondes alors que le relais a écrit `enregistré par l'API (Phase 4.1 bis)` : vérifiez `$TO` (`echo $TO`) et l'heure du serveur (`date -u`). `curl: (28) Operation timed out` : `--max-time` plus court que `wait`.

### Étape 4.2 — 🖥️ Serveur : HTML, image intégrée et pièce jointe

Ce script envoie un mail complet : texte, HTML avec une image intégrée (`cid:`) et une pièce jointe.

```bash
python3 - <<'EOF'
import base64, os, smtplib
from email.message import EmailMessage
from email.utils import make_msgid

png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==")
cid = make_msgid(domain="test.local")

msg = EmailMessage()
msg["From"] = "Test relais <test@exemple.fr>"
msg["To"] = os.environ["TO"]
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

Le mail complet, lu par son ID (le premier `"id"` de la liste) :

```bash
ID=$(curl -4 -s -H "Authorization: Bearer $JETON" "$API/v1/messages?limit=1" | grep -oE '"id":"[^"]+"' | head -1 | cut -d'"' -f4)
curl -4 -s -H "Authorization: Bearer $JETON" "$API/v1/messages/$ID" \
  | python3 -c 'import json, sys; m = json.load(sys.stdin); print(m["text"].strip()); print("cid:" in m["html"], len(m["headers"]) > 0)'
```

✅ Attendu : `Version texte du mail de test.`, puis `True True` (le HTML référence l'image intégrée, les en-têtes sont là).

☁️ **OVH** : dans phpMyAdmin, la ligne du mail a `html_body` et `headers` remplis. Par FTP, `var/messages/<ID>/` contient deux fichiers `part-…`.

### Étape 4.3 — 🖥️ Serveur : une pièce jointe trop grosse

```bash
head -c 6000000 /dev/urandom > /tmp/gros.bin
swaks --server 127.0.0.1 --port 25 --to "${TO:?TO est vide : export TO=essai@votre-sous-domaine}" --header "Subject: Phase 4.3" --attach @/tmp/gros.bin
rm /tmp/gros.bin
```

✅ Attendu dans les logs :

- `… enregistré par l'API (Phase 4.3), 0 pièce(s) envoyée(s)` ;
- puis l'avertissement `pièce … « gros.bin » (6000000 octets) au-delà de la limite de l'API, seule sa description est enregistrée`.

Le mail est quand même livré et tagué `relayed`. Seul le contenu de la pièce manque (état `too_large`).

### Étape 4.3 bis — 🖥️ Serveur : un mail trop volumineux, tronqué par le relais

Au-delà de `max_body_size` (16 Mo par défaut), l'API répond `413` et le relais renvoie le mail raccourci. Pour le tester sans fabriquer un mail de 16 Mo, baissez la limite le temps de l'essai.

1. ☁️ **OVH** : dans `config.php`, section `webhook`, mettez `'max_body_size' => 200000,` (200 Ko), puis envoyez le fichier en FTP.
2. 🖥️ Un mail d'environ 450 Ko de texte :

```bash
yes 'Ligne de texte du test de mail trop volumineux, phase 4.3 bis.' | head -n 7000 > /tmp/long.txt
swaks --server 127.0.0.1 --port 25 --to "${TO:?TO est vide : export TO=essai@votre-sous-domaine}" --header "Subject: Phase 4.3 bis" --body @/tmp/long.txt
rm /tmp/long.txt
curl -4 -s -H "Authorization: Bearer $JETON" "$API/v1/messages?limit=1" | grep -oE '"(subject|complete|truncated)":[^,]*'
```

✅ Attendu dans les logs du relais :

- `[relay] WARN  mail … trop volumineux pour l'API (… octets, limite 200000 octets) : envoi d'une version tronquée de … octets (essai 1/3)` ;
- puis `… enregistré par l'API (Phase 4.3 bis), 0 pièce(s) envoyée(s), essai 1`.

✅ Attendu côté API : `"subject":"Phase 4.3 bis"`, `"complete":true` et `"truncated":true`. 💻 Dans le Dashboard, le mail porte le badge **tronqué**, et son texte se termine par `[… contenu tronqué par le relais : mail trop volumineux pour l'API]`.

❌ `refusé par l'API (API (message) refus définitif : HTTP 413 …)` sans ligne `version tronquée` : le relais est une version précédente (reconstruisez l'image, [Partie 7](07-relay.md)). `"truncated"` absent de la réponse : l'API est une version précédente (renvoyez `src/` et `templates/` en FTP, et importez la migration `sql/migrations/2026-11-message-truncated.sql`).

3. ☁️ **OVH** : remettez `max_body_size` à sa valeur d'origine (ou retirez la ligne : 16 Mo par défaut) et renvoyez `config.php`.

### Étape 4.4 — 🖥️ Serveur : une rafale de 10 mails

Ce test vérifie qu'aucun mail n'est ignoré quand ils arrivent dans la même seconde (`MP_WEBHOOK_LIMIT=0`), et que le relais les envoie un par un.

```bash
AVANT=$(curl -4 -s -H "Authorization: Bearer $JETON" "$API/v1/messages?limit=1" | grep -o '"total":[0-9]*')
for i in $(seq 1 10); do
  swaks --server 127.0.0.1 --port 25 --to "${TO:?TO est vide : export TO=essai@votre-sous-domaine}" --header "Subject: Rafale $i" >/dev/null
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
for i in 1 2 3; do swaks --server 127.0.0.1 --port 25 --to "${TO:?TO est vide : export TO=essai@votre-sous-domaine}" --header "Subject: Pendant l'arret $i" >/dev/null; done
sudo journalctl -u mailpit -n 5 --no-pager | grep -i webhook
sudo systemctl start mailpit-relay
```

✅ Attendu :

- pendant l'arrêt, Mailpit écrit des erreurs de webhook (`connection refused` ou `no such host`). C'est normal ;
- au redémarrage, les 3 mails `Pendant l'arret …` sont enregistrés par l'API en quelques secondes.

> 💡 Pour voir la coupure dans la page **Statut** (étape 3.6), attendez **2 minutes** avant `sudo systemctl start mailpit-relay` : la page affiche alors **Déconnecté depuis …** (badge rouge). Après le redémarrage, elle revient à **Connecté**, et la coupure apparaît dans la liste **Coupures**, avec sa durée.

### Étape 5.2 — 🖥️ Serveur : l'API refuse le relais (mauvais jeton)

On simule un jeton désynchronisé, par exemple après avoir changé `config.php` sans mettre à jour le relais.

1. Dans `/etc/mailpit/relay.env`, modifiez **un seul caractère** de `RELAY_API_TOKEN`, puis :
   ```bash
   sudo systemctl restart mailpit-relay
   swaks --server 127.0.0.1 --port 25 --to "${TO:?TO est vide : export TO=essai@votre-sous-domaine}" --header "Subject: Phase 5.2"
   ```
2. ✅ Attendu dans les logs :
   ```
   [relay] WARN  mail … : échec de l'essai 1 (API (message) : HTTP 401 …), nouvel essai dans 30 s
   ```
   puis l'essai 2 au bout de 30 s, l'essai 3 au bout d'une minute, etc. Le mail n'a **pas** le tag `relayed` dans Mailpit. Le ping de santé est refusé lui aussi, **une** seule ligne pour l'instant (puis un rappel toutes les 10 minutes) :
   ```
   [relay] WARN  ping de santé non enregistré : API : HTTP 401 {"error":"Unauthorized"} (rappel toutes les 10 minutes tant que l'échec dure)
   ```
   Au bout de 90 secondes, la page **Statut** passe à **Déconnecté depuis …**.
3. Remettez le bon jeton dans `relay.env`, puis :
   ```bash
   sudo systemctl restart mailpit-relay
   ```
4. ✅ Attendu : `mail … enregistré par l'API (Phase 5.2)` aussitôt après le redémarrage, et la page **Statut** de nouveau sur **Connecté**, avec une coupure dans la liste.

> 💡 La même chose se produit si l'API est en panne ou si l'IP est refusée (`HTTP 403`, `injoignable`). Le relais réessaie sans limite de durée : il suffit de corriger le problème.

### Étape 5.3 — 🖥️ Serveur : renvoyer un mail déjà relayé

Notez d'abord le `total` de l'API :

```bash
curl -4 -s -H "Authorization: Bearer $JETON" "$API/v1/messages?limit=1" | grep -o '"total":[0-9]*'
```

Retirez ensuite le tag `relayed` d'un mail déjà livré. Vous pouvez le faire dans l'interface de Mailpit ([tunnel SSH](01-podman.md#étape-7--accéder-à-linterface-web)), ou avec son API, en mettant l'`ID` du mail tel qu'il apparaît dans les logs du relais :

```bash
read -rsp 'MP_UI_AUTH (admin:motdepasse) : ' MPAUTH; echo
curl -s -u "$MPAUTH" -X PUT -H 'Content-Type: application/json' \
  -d '{"IDs":["ID_DU_MAIL"],"Tags":[]}' http://127.0.0.1:8025/api/v1/tags; echo
```

✅ Attendu :

- `ok`, puis dans les 30 s une nouvelle ligne `enregistré par l'API (…)` dans les logs du relais ;
- le tag `relayed` est de nouveau posé ;
- côté API, **pas** de deuxième ligne : le `total` ne change pas (doublon reconnu grâce à la contrainte d'unicité sur `mailpit_id`).

Contrôle global : chaque mail tagué `relayed` dans Mailpit correspond à exactement un mail dans l'API. Les deux nombres doivent être égaux (en l'absence de purge et de `RELAY_QUERY`) :

```bash
curl -s -u "$MPAUTH" 'http://127.0.0.1:8025/api/v1/search?query=tag:relayed&limit=1' | grep -oE '"messages_count":[0-9]*'
curl -4 -s -H "Authorization: Bearer $JETON" "$API/v1/messages?limit=1" | grep -o '"total":[0-9]*'
```

### Étape 5.4 — 🖥️ Serveur : redémarrage complet du serveur (facultatif)

```bash
sudo reboot
```

Après le redémarrage :

```bash
sudo systemctl status mailpit mailpit-relay --no-pager | grep -E '●|Active'
swaks --server 127.0.0.1 --port 25 --to "${TO:?TO est vide : export TO=essai@votre-sous-domaine}" --header "Subject: Apres reboot"
sudo journalctl -u mailpit-relay -n 5 --no-pager
```

✅ Attendu : les deux services `active (running)` sans action de votre part, et le mail `Apres reboot` enregistré par l'API.

---

## Phase 6 — Remise en ordre

1. 🖥️ `sudo podman exec mailpit-relay php /app/bin/status.php` : **0** en attente, **0** refusé. 💻 Page **Statut** du Dashboard : **Connecté**, `0` en attente, `0` refusé. Les coupures provoquées en phase 5 restent dans la liste pendant 24 heures.
2. 🖥️ Effacez les secrets du terminal : `unset JETON MPAUTH`.
3. 🖥️ **`RELAY_QUERY`** : vous pouvez garder la ligne `after:…`. Elle empêche seulement de relayer les mails reçus **avant** cette date. Si vous la retirez, lancez `sudo systemctl restart mailpit-relay` : le relais envoie alors ces anciens mails à l'API, un par un. Suivez le rattrapage avec `status.php`, jusqu'à **0** en attente.
4. ☁️ **Mails de test dans l'API** : supprimez-les si vous voulez une base propre, en visant **uniquement** la période des tests. `created_at` est en UTC. Un `DELETE` sans condition effacerait aussi les anciens mails relayés au point 3, et les vrais mails :
   ```sql
   DELETE FROM mailpit_messages WHERE created_at >= 'AAAA-MM-JJ HH:MM:SS';
   ```
   Les pièces (`mailpit_parts`) partent avec leurs mails. Les dossiers `var/messages/<ID>/` correspondants sont effacés par le nettoyage de l'API (automatique après une requête du relais, ou `php bin/cleanup.php`) une fois restés 1 heure sans modification. Si vous les laissez, les tests disparaissent de toute façon au bout de 7 jours (`webhook.max_age_days`), base **et** fichiers.
5. ☁️ **`config.php`** : vérifiez `'debug' => false` et `'force_https' => true`.
6. ☁️ **`var/log/api.log`** : relisez-le (et `api.log.1` s'il existe : le log y est archivé à 10 Mo), puis videz-le si vous voulez repartir d'un log propre. Il garde la trace des tests 401 et 403 (`[auth]`, `[ip-filter]`, `[https]`). Les lignes `[maintenance] mails supprimés : …` résument les nettoyages automatiques. Toute autre erreur (PHP, base de données, `[prune]`, `[maintenance] nettoyage impossible`) est à examiner.
7. Cochez les phases dans le [suivi](#suivi) en haut de page.

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
| 2026-11-09 | 3.2 – 3.4 | Après les règles DNS : `mailpit` résolu (`10.89.25.2`), connexion au port 8025 OK, le mail `Phase 0` est enregistré par l'API au 1er essai. L'API n'autorisant que l'IPv4, le relais sort donc bien en IPv4 (étape 3.4 validée du même coup) | — |
| 2026-11-09 | 3.3 – 3.5 | `/health` → `{"status":"ok"}`, `dns_enabled: true`, aucun port publié | — |
| 2026-11-09 | 4.1 | 1er envoi : aucun mail, car l'adresse d'exemple était restée dans la commande (`>/dev/null` masquait le refus). 2e envoi : tag `relayed`, mail resté non lu | Variable `TO` exportée en début de phase 4, utilisée par toutes les commandes des phases 4 et 5 |
| 2026-11-09 | 4.1 (suite) | Envoi à 12:09:23, enregistré par l'API à 12:09:23 (webhook immédiat, aucune erreur de webhook dans Mailpit). API : `complete: true` | — |
| 2026-11-09 | 4.2 | `complete: true`. Image intégrée (`inline`, `point.png`) et pièce jointe (`attachment`, `note.txt`) toutes deux `stored` | — |
| 2026-11-09 | 4.3 | Mail de 8,2 Mo (pièce de 6 Mo) : livré, `complete: true`, pièce `too_large` (6000000 octets), avertissement dans les logs du relais | — |
| 2026-11-09 | 4.4 | Rafale de 10 mails : 10 acceptés, 10 enregistrés par l'API, `total` +10, `status.php` : 0 en attente, 0 refusé | — |
| 2026-11-09 | 4.5 | Vrai mail depuis Internet avec une photo (130 Ko) : `complete: true`, pièce `stored`, aucun `UFW BLOCK`. La règle SMTP du réseau `mailpit` (`10.89.25.0/24`) fonctionne | Ancienne règle `10.88.0.0/16 1025/tcp` devenue inutile : à supprimer |
| 2026-11-09 | 4.5 (suite) | Ancienne règle ufw `10.88.0.0/16 1025/tcp` supprimée | — |
| 2026-11-09 | 5.1 | La boucle d'envoi semble bloquée : `$TO` était vide dans le terminal, et `swaks` attendait la saisie du destinataire (`To:`) | Commandes protégées par `${TO:?…}` : elles s'arrêtent tout de suite avec un message si `TO` est vide |
| 2026-11-09 | 5.1 (suite) | Relais arrêté : 3 mails acceptés par Mailpit, 3 erreurs de webhook (attendues). Au redémarrage, les 3 mails sont enregistrés par l'API dans la même seconde. `status.php` : 0 / 0 | — |
| 2026-11-09 | 5.2 | Faux jeton : `HTTP 401`, nouvel essai prévu dans 30 s, mail non tagué. Jeton rétabli : mail enregistré aussitôt après le redémarrage | — |
| 2026-11-09 | 5.3 | Tag `relayed` retiré via l'API de Mailpit (`PUT /api/v1/tags`) : mail renvoyé, reconnu comme doublon (contrainte `uq_mailpit_id`), tag reposé. Total API : 22 | — |
| 2026-11-09 | 5.4 | Redémarrage du serveur : relais démarré seul (13:42:33), mail `Apres reboot` enregistré par l'API, sans `WARN` ni `ERROR` | — |
| 2026-11-09 | 6 | `RELAY_QUERY` retiré : les mails antérieurs aux tests sont envoyés à l'API | Phase 6 : le nettoyage de la base vise désormais la seule période des tests |
| 2026-11-09 | 6 (suite) | Anciens mails relayés sans `WARN` ni `ERROR`, `status.php` : 0 / 0. Secrets du shell effacés, `debug => false`. `api.log` : uniquement les refus des tests (`[ip-filter]`, `[auth]`) et des robots qui scannent Internet (`/wp-login.php`, `/xmlrpc.php`…), tous refusés par le filtre IP. Aucune erreur PHP ni base de données | Vider `api.log` de temps en temps : les robots le remplissent |

---

[←](07-relay.md) · [Sommaire](../mailpit.md)
