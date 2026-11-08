# Partie 6 — Appeler un webhook à la réception d'un mail

> Remplacez les valeurs d'exemple (`mondomaine.fr`, `admin:MotDePasseSolide`…) comme indiqué dans [Avant de commencer](../mailpit.md#avant-de-commencer).

Mailpit peut prévenir une autre application dès qu'un mail arrive : il envoie une requête HTTP `POST` vers l'URL de votre choix (un « webhook »). Pratique pour déclencher un test automatisé, une notification (Slack, Discord, ntfy…) ou un traitement du mail reçu.

```
Mail reçu ──► Mailpit ── POST JSON (résumé du mail) ──► votre service
                 ▲                                          │
                 └──── GET /api/v1/message/{ID} (optionnel) ┘
```

La requête contient un **résumé** du mail (expéditeur, destinataires, sujet, extrait…), pas son contenu complet. Si votre service a besoin du corps ou des pièces jointes, il les récupère ensuite via l'API de Mailpit avec l'`ID` reçu.

> 💡 Pour enregistrer chaque mail reçu dans une base MySQL sur un hébergement OVH, une API prête à déployer est fournie dans le dossier [`api/`](../../api/README.md).

## Étape 1 — Choisir l'URL du webhook

Votre service doit :

- accepter une requête `POST` avec un corps JSON ;
- répondre avec un code **2xx** en **moins de 5 secondes**. Au-delà, Mailpit abandonne.

L'URL dépend de l'endroit où tourne ce service :

| Le service tourne… | URL à utiliser |
|---|---|
| ailleurs sur Internet | `https://mon-service.exemple.fr/hook` |
| sur le **même serveur** que Mailpit | `http://host.containers.internal:PORT/hook` |
| sur une autre machine de votre réseau local (serveur à la maison) | `http://192.168.x.x:PORT/hook` (l'IP locale de cette machine) |

> ⚠️ **N'utilisez pas `127.0.0.1` ou `localhost`** pour un service installé sur le serveur. Mailpit tourne dans un conteneur : pour lui, `127.0.0.1` désigne le conteneur lui-même, pas le serveur. `host.containers.internal` est un nom que Podman fournit pour joindre le serveur depuis le conteneur. Le service doit alors écouter sur `0.0.0.0` (ou l'IP du serveur), pas seulement sur `127.0.0.1`.

> 💡 Mailpit n'ajoute **ni signature ni jeton** à ses requêtes. Si votre service est joignable depuis Internet, mettez un secret long et aléatoire dans l'URL (par exemple `https://mon-service.exemple.fr/hook?token=UnSecretTresLong`) et rejetez les requêtes qui ne l'ont pas. Générez-en un avec `openssl rand -hex 24`.

## Étape 2 — Activer le webhook dans Mailpit

Ajoutez à la fin de `/etc/mailpit/mailpit.env` ([Partie 1, étape 3](01-podman.md#étape-3--créer-le-fichier-de-configuration-de-mailpit)) :

```ini
# Requête POST envoyée à chaque nouveau mail
MP_WEBHOOK_URL=https://mon-service.exemple.fr/hook?token=UnSecretTresLong

# Un appel pour CHAQUE mail (voir l'encadré ci-dessous)
MP_WEBHOOK_LIMIT=0
```

Puis redémarrez :

```bash
sudo systemctl restart mailpit
sudo systemctl status mailpit
```

> ⚠️ **Pourquoi `MP_WEBHOOK_LIMIT=0` ?** Malgré le texte de l'aide (« requests per second »), cette valeur est en réalité le **nombre minimum de secondes entre deux appels**. Par défaut, elle vaut `1` : si deux mails arrivent dans la même seconde, le second ne déclenche **aucun** appel. Il n'est pas mis en attente, il est simplement ignoré. Avec `0`, chaque mail déclenche son appel. Gardez une valeur supérieure à 0 seulement si vous voulez volontairement limiter les appels (par exemple pour éviter une rafale de notifications sur votre téléphone quand beaucoup de mails arrivent d'un coup).

Autres réglages facultatifs :

| Variable | Rôle |
|---|---|
| `MP_WEBHOOK_DELAY=2` | Attend 2 secondes avant chaque appel (défaut : `0`) |
| `MP_LABEL=Mailpit test` | Ajoute l'en-tête `Mailpit-Label: Mailpit test` à chaque requête. Utile si plusieurs instances de Mailpit appellent le même service. Ce nom s'affiche aussi dans l'interface web. |

> ℹ️ L'URL n'est pas valide (faute de frappe, `https://` oublié…) ? Mailpit refuse de démarrer et l'erreur apparaît dans `sudo journalctl -u mailpit -n 30`.

## Étape 3 — Tester avec un récepteur temporaire

Pour voir ce que Mailpit envoie avant de brancher votre vrai service, lancez sur le serveur un petit récepteur qui affiche les requêtes reçues.

1. Mettez provisoirement dans `/etc/mailpit/mailpit.env` :
   ```ini
   MP_WEBHOOK_URL=http://host.containers.internal:9000/hook
   MP_WEBHOOK_LIMIT=0
   ```
   puis `sudo systemctl restart mailpit`.
2. Dans un **premier terminal** sur le serveur, lancez le récepteur (il écoute sur le port 9000) :
   ```bash
   python3 - <<'EOF'
   from http.server import BaseHTTPRequestHandler, HTTPServer
   class Hook(BaseHTTPRequestHandler):
       def do_POST(self):
           body = self.rfile.read(int(self.headers.get("Content-Length", 0)))
           print(self.headers, body.decode(), sep="", flush=True)
           self.send_response(204)
           self.end_headers()
   HTTPServer(("0.0.0.0", 9000), Hook).serve_forever()
   EOF
   ```
3. Dans un **second terminal**, envoyez un mail de test :
   ```bash
   swaks --server 127.0.0.1 --port 25 --to essai@test.mondomaine.fr
   ```
4. Le premier terminal doit afficher les en-têtes puis le JSON du mail.
5. Arrêtez le récepteur avec `Ctrl+C`, puis remettez l'URL de votre vrai service dans `mailpit.env` et redémarrez Mailpit.

> ⚠️ Ce récepteur écoute sur toutes les interfaces. N'ouvrez **pas** le port 9000 dans le pare-feu, et ne le laissez pas tourner après le test.

Si rien ne s'affiche, voir le [dépannage](#dépannage-webhook) plus bas.

---

## Ce que Mailpit envoie

Une requête `POST` avec ces en-têtes :

```
Content-Type: application/json
User-Agent: Mailpit/v1.x.x
Mailpit-Label: Mailpit test      (seulement si MP_LABEL est défini)
```

Et un corps JSON qui ressemble à ceci (exemple raccourci) :

```json
{
  "ID": "Wdyx4jVvBWELmoNePrh8Ff",
  "MessageID": "20261108101542.012345@gmail.com",
  "Read": false,
  "From": { "Name": "Alice", "Address": "alice@gmail.com" },
  "To": [ { "Name": "", "Address": "essai@test.mondomaine.fr" } ],
  "Subject": "Bonjour",
  "Created": "2026-11-08T10:15:42.123+01:00",
  "Tags": [],
  "Size": 4321,
  "Attachments": 0,
  "Snippet": "Les 250 premiers caractères du texte du mail…"
}
```

On y trouve aussi les champs `Cc`, `Bcc`, `ReplyTo`, `ReturnPath` et `Username`.

**Récupérer le mail complet** avec l'`ID` reçu, via l'API (mêmes identifiants que `MP_UI_AUTH` ; curl demande le mot de passe) :

```bash
# Contenu décodé (texte, HTML, liste des pièces jointes…) en JSON
curl -s -u admin http://127.0.0.1:8025/api/v1/message/ID_DU_MAIL

# Mail brut (format .eml)
curl -s -u admin http://127.0.0.1:8025/api/v1/message/ID_DU_MAIL/raw
```

> ℹ️ L'API n'écoute que sur `127.0.0.1:8025` du serveur ([Partie 1, étape 4](01-podman.md#étape-4--créer-le-service-systemd-quadlet)). Un service hébergé ailleurs ne peut donc pas l'appeler directement : soit il se contente du résumé, soit il tourne sur le serveur, soit vous exposez l'interface derrière un reverse proxy (voir « Pour aller plus loin » en [Partie 1](01-podman.md#pour-aller-plus-loin)).

## Limites à connaître

- **Pas de nouvel essai.** Si votre service est arrêté, trop lent (plus de 5 s) ou répond par une erreur, Mailpit écrit un avertissement dans ses logs et passe à la suite. Le mail, lui, est bien enregistré dans Mailpit.
- **Un seul webhook.** On ne peut indiquer qu'une URL. Pour prévenir plusieurs services, faites-les appeler par un service intermédiaire.
- **Si vous ne pouvez perdre aucun événement**, ne comptez pas uniquement sur le webhook : votre service peut aussi interroger régulièrement l'API (`GET /api/v1/messages`) pour rattraper les mails manqués.

---

## Dépannage webhook

Suivez d'abord les logs pendant un envoi de test :

```bash
sudo journalctl -u mailpit -f
```

Un appel **réussi** n'écrit rien dans les logs. Seuls les échecs apparaissent, ce qui donne les messages du tableau ci-dessous.

| Symptôme | Cause probable / solution |
|---|---|
| Rien dans les logs, rien côté service | `MP_WEBHOOK_URL` absent ou mal orthographié dans `mailpit.env`, ou Mailpit pas redémarré après la modification |
| Log `connection refused` | Le service n'écoute pas, ou seulement sur `127.0.0.1`. Faites-le écouter sur `0.0.0.0` et utilisez `host.containers.internal` (étape 1) |
| Log `i/o timeout` ou `Client.Timeout exceeded` | Service trop lent (> 5 s), ou pare-feu. Avec ufw et un service sur le même serveur, le conteneur (réseau `10.88.0.0/16`) doit pouvoir joindre le serveur : `sudo ufw allow from 10.88.0.0/16 to any port 9000 proto tcp` (adaptez le port) |
| Log `no such host` | Faute dans le nom d'hôte. Pour `host.containers.internal`, vérifiez qu'il existe dans le conteneur : `sudo podman exec mailpit grep host.containers.internal /etc/hosts` |
| Log avec un code `401`, `403`, `404`… | Le service a répondu par une erreur : mauvais chemin, ou jeton manquant ou incorrect dans l'URL |
| Certains mails ne déclenchent pas d'appel | `MP_WEBHOOK_LIMIT` absent ou supérieur à 0 : mettez `MP_WEBHOOK_LIMIT=0` (étape 2) |

### Debian 12

Si vous avez suivi l'[annexe Debian 12](01-podman.md#annexe--debian-12), le service s'appelle `container-mailpit` : utilisez `sudo systemctl restart container-mailpit` et `sudo journalctl -u container-mailpit -f`. Le fichier `mailpit.env` est relu à chaque redémarrage, inutile de régénérer le service.

---

[←](05-maintenance.md) · [Sommaire](../mailpit.md)
