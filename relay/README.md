# Relais Mailpit → API

Petit service **PHP 8.5**, sans dépendance, qui tourne dans **Podman** sur le même serveur que Mailpit. Il transmet à l'[API](../api/README.md) le **mail complet** (en-têtes, texte, HTML, pièces jointes et images intégrées), alors que le webhook de Mailpit n'envoie qu'un résumé.

```
Mailpit ── webhook ──► relais : « il y a du nouveau » (le contenu du webhook est ignoré)
   ▲                     │
   │  1. recherche les mails sans le tag « relayed »
   │  2. lit le mail complet et ses pièces
   └─ 4. pose le tag « relayed »
                         │ 3. POST /v1/messages                      (JSON du mail)
                         │    POST /v1/messages/{id}/part/{partId}   (une requête par pièce, avec SHA-256)
                         │    POST /v1/health toutes les 30 s        (ping de santé, processus à part)
                         ▼    Authorization: Bearer <RELAY_API_TOKEN>
                https://api.mondomaine.fr ── répond "complete": true quand tout est enregistré
```

- **Rien n'est enregistré par le relais.** C'est Mailpit qui sert de file d'attente : un mail est « à relayer » tant qu'il n'a pas le tag `relayed`. Le conteneur n'a pas de volume et peut tourner en lecture seule.
- **Aucun mail perdu.** Le tag n'est posé que lorsque l'API a répondu `"complete": true`, c'est-à-dire après avoir enregistré le message **et** toutes ses pièces. Si l'API est injoignable, si le relais est arrêté ou redémarre au milieu d'un envoi, le mail reste sans tag et il est renvoyé (30 s, 1 min, 2 min… puis toutes les heures, sans limite de durée). L'API ignore les doublons : renvoyer un mail est sans risque.
- **Un mail à la fois.** Le relais envoie le message, puis ses pièces une par une, puis pose le tag, avant de passer au mail suivant : jamais deux requêtes simultanées vers l'API pour les mails. Seul le [ping de santé](#ping-de-santé), une petite requête toutes les 30 secondes, part à côté, depuis son propre processus. Un verrou empêche aussi un second relais de démarrer dans le même conteneur. Ne lancez qu'**un seul** conteneur relais par Mailpit.
- **Les plus anciens d'abord.** Après un retard (API en panne, relais arrêté), les mails en attente sont relayés du plus ancien au plus récent, par lots de `RELAY_BATCH_SIZE` : ce sont les plus anciens que Mailpit supprime en premier (`MP_MAX_MESSAGES`, `MP_MAX_AGE`). Un mail qui attend son nouvel essai ne bloque pas les suivants.
- **Ping de santé.** Toutes les 30 secondes, le relais envoie à l'API un ping avec sa latence et le nombre de mails en attente et refusés. Le Dashboard de l'API en tire sa page **Statut** : connecté ou non, latence et coupures sur 24 heures. Voir [Ping de santé](#ping-de-santé).
- **Le webhook n'est qu'un signal.** Il déclenche une recherche immédiate. Sans lui (webhook perdu, relais arrêté…), le relais cherche de toute façon toutes les `RELAY_POLL_INTERVAL` secondes. Un faux appel ne peut rien injecter : tout est relu dans Mailpit.
- **Jeton configuré des deux côtés** : `RELAY_API_TOKEN` ici, `webhook.token` dans le `config.php` de l'API. Il voyage dans l'en-tête `Authorization`, jamais dans l'URL : l'API refuse un jeton passé en `?token=…` (il apparaîtrait dans les logs d'accès de l'hébergement). Le relais est donc le seul moyen d'alimenter l'API.
- **HTTPS obligatoire** : `RELAY_API_URL` doit commencer par `https://`, sinon le relais refuse de démarrer. Le jeton et les mails ne circulent jamais en clair. `http://` n'est accepté que vers cette machine (`localhost`, `127.0.0.1`, `::1`), pour des tests. De son côté, l'API refuse toute requête `POST` en `http://` (`403`).
- **Intégrité vérifiée.** Chaque pièce est envoyée avec son empreinte SHA-256, comparée à celle de Mailpit avant l'envoi, puis par l'API avant l'enregistrement.
- **État « non lu » conservé** : lire un mail via l'API de Mailpit le marque comme lu, le relais le remet en « non lu ».
- **Aucun port publié** : le relais n'est joignable que par les conteneurs du réseau Podman `mailpit`.

Installation pas à pas : **[Partie 7 du tutoriel](../docs/mailpit/07-relay.md)**.

## Fichiers

| Fichier | Rôle |
|---|---|
| `Containerfile` | image Podman (`php:8.5-cli-alpine` + extension `pcntl`) |
| `relay.env.example` | modèle de configuration (`/etc/mailpit/relay.env`) |
| `bin/relay.php` | point d'entrée : lance le récepteur et le ping de santé, les surveille, et relaie les mails en continu |
| `bin/heartbeat.php` | ping de santé (`POST /v1/health` toutes les 30 s), lancé par `bin/relay.php` |
| `bin/status.php` | affiche les mails en attente et les mails refusés par l'API (lus dans Mailpit) |
| `bin/retry-failed.php` | retire le tag d'échec des mails refusés : ils sont renvoyés aussitôt |
| `public/index.php` | récepteur HTTP (`POST /hook`, `GET /health`) |
| `src/` | code du relais |
| `composer.json`, `phpunit.xml.dist`, `tests/` | [tests automatisés](#tests) uniquement : ni Composer ni PHPUnit ne sont dans l'image |

## Configuration

Variables d'environnement (voir `relay.env.example`) :

| Variable | Défaut | Rôle |
|---|---|---|
| `RELAY_API_URL` | *(obligatoire)* | adresse de base de l'API (ex. `https://api.mondomaine.fr`), **sans** `/v1/messages` ni `?token=…`. En **`https://`** (`http://` seulement vers `localhost`, `127.0.0.1` ou `::1`) |
| `RELAY_API_TOKEN` | *(obligatoire)* | jeton partagé avec l'API (`webhook.token`), 32 caractères minimum |
| `RELAY_MAILPIT_URL` | `http://mailpit:8025` | Mailpit vu depuis le conteneur |
| `RELAY_MAILPIT_AUTH` | *(aucun)* | identifiants `MP_UI_AUTH` au format `utilisateur:motdepasse` |
| `RELAY_POLL_INTERVAL` | `30` | secondes entre deux recherches, en l'absence de webhook |
| `RELAY_BATCH_SIZE` | `50` | mails lus par recherche dans Mailpit (500 maximum). Un passage relaie environ un lot, du plus ancien au plus récent, et lit au plus 20 lots |
| `RELAY_TAG_DONE` | `relayed` | tag posé dans Mailpit quand l'API a tout enregistré |
| `RELAY_TAG_FAILED` | `relay-failed` | tag posé quand l'API refuse définitivement un mail |
| `RELAY_QUERY` | *(aucun)* | filtre de recherche Mailpit en plus, ex. `after:2026-11-08` |
| `RELAY_LABEL` | *(aucun)* | nom de l'instance, envoyé dans l'en-tête `Mailpit-Label` |
| `RELAY_TIMEOUT` | `60` | délai maximal d'une requête HTTP (secondes). Les requêtes du ping de santé attendent au plus 10 secondes |
| `RELAY_HEARTBEAT_INTERVAL` | `30` | secondes entre deux pings de santé (`POST /v1/health`). `0` : pas de ping (la page **Statut** du Dashboard affiche alors « Aucun ping reçu »). ⚠️ Gardez `60` au plus : la page **Statut** compte une coupure dès 90 secondes sans ping |
| `RELAY_KEEP_UNREAD` | `true` | remet en « non lu » les mails lus par le relais |

> ⚠️ **Premier démarrage** : tous les mails déjà présents dans Mailpit (sans le tag `relayed`) sont envoyés à l'API. Pour ne relayer que les nouveaux, mettez par exemple `RELAY_QUERY=after:2026-11-08` (date du jour).

## Ce qui est envoyé à l'API

**1. Le mail**, en JSON : le résumé de Mailpit, plus :

```json
{
  "ID": "Wdyx4jVvBWELmoNePrh8Ff",
  "…": "…autres champs du résumé (From, To, Subject, Created, Size…)…",
  "ReturnPath": "alice@exemple.fr",
  "Date": "2026-11-08T21:15:01+01:00",
  "Text": "Corps du mail en texte",
  "HTML": "<p>Corps du mail en HTML <img src=\"cid:logo123\"></p>",
  "Headers": { "Received": ["…", "…"], "X-Mailer": ["…"] },
  "Parts": [
    { "PartID": "1.2", "Kind": "inline", "FileName": "logo.png", "ContentType": "image/png", "ContentID": "logo123", "Size": 2048 },
    { "PartID": "2", "Kind": "attachment", "FileName": "devis.pdf", "ContentType": "application/pdf", "ContentID": "", "Size": 123456 }
  ]
}
```

**2. Les pièces** que l'API réclame dans sa réponse (`"missing"`), une par requête `POST /v1/messages/{id}/part/{partId}`, en binaire, avec l'en-tête `X-Content-SHA256`. L'API ne réclame pas les pièces au-delà de sa limite (5 Mo par défaut) : elle les liste dans `"too_large"`, et le relais l'écrit dans ses logs.

**Réponses de l'API :**

| Réponse | Ce que fait le relais |
|---|---|
| `2xx` avec `"complete": true` | pose le tag `relayed` : mail livré |
| `2xx` avec `"complete": false` | envoie les pièces manquantes, puis vérifie à nouveau |
| Message : `400`, `413`, `415`, `422`<br>Pièce : `409`, `413`, `415` | refus définitif : pose le tag `relay-failed` et écrit l'erreur dans les logs |
| Tout le reste : `401` (jeton), `403` (IP), `404`, `422` sur une pièce (empreinte), `5xx`, API injoignable… | nouvel essai plus tard, sans limite : vous avez le temps de corriger |

## Ping de santé

`bin/relay.php` lance `bin/heartbeat.php` dans un **processus séparé**, comme le récepteur : l'envoi d'un gros mail ne retarde pas les pings. Toutes les `RELAY_HEARTBEAT_INTERVAL` secondes (30 par défaut), il :

1. compte dans Mailpit les mails **en attente** et **refusés** (mêmes recherches que `bin/status.php`, un seul résultat lu : seul le total compte) ;
2. envoie `POST {RELAY_API_URL}/v1/health`, avec le même jeton et le même en-tête `Mailpit-Label` que les mails :

```json
{ "latency_ms": 85, "pending": 0, "failed": 2 }
```

| Champ | Contenu |
|---|---|
| `latency_ms` | durée de l'aller-retour HTTP du ping **précédent**, en millisecondes. `null` pour le premier ping, ou si le précédent a échoué |
| `pending` | mails en attente de relais dans Mailpit. `null` si Mailpit est injoignable : le ping part quand même |
| `failed` | mails refusés par l'API (tag `relay-failed`). `null` si Mailpit est injoignable |

L'API enregistre chaque ping (table `mailpit_health_pings`, gardée 48 heures). La page **Statut** de son Dashboard affiche : connecté (dernier ping il y a moins de 90 secondes) ou déconnecté depuis quand, dernière latence, mails en attente et refusés, disponibilité et graphique de la latence sur 24 heures, liste des coupures. Voir le [README de l'API](../api/README.md#page-statut).

**Journal sans inondation** : un échec du ping (API injoignable, `401`, `403`, `5xx`…) ou de Mailpit est écrit **une fois**, puis rappelé **toutes les 10 minutes** tant qu'il dure, et une ligne signale le rétablissement :

```
[relay] WARN  ping de santé non enregistré : API injoignable : … (rappel toutes les 10 minutes tant que l'échec dure)
[relay] WARN  ping de santé non enregistré : API injoignable : … (en échec depuis 10 min, 21 essais)
[relay] INFO  ping de santé de nouveau accepté par l'API, après 14 min d'échec (29 essai(s) en échec)
```

Si le processus du ping s'arrête (plantage), `bin/relay.php` s'arrête en erreur, comme pour le récepteur, et Podman / systemd le redémarre. `podman stop` arrête les trois processus proprement.

> 💡 Une API d'une version précédente, sans `POST /v1/health`, répond `404` : le relais l'écrit dans son journal (une fois, puis toutes les 10 minutes), et les mails sont relayés normalement. Mettez l'API à jour, ou désactivez le ping avec `RELAY_HEARTBEAT_INTERVAL=0`.

## Limites

- Un mail **supprimé de Mailpit** avant d'avoir été relayé (à la main, ou par `MP_MAX_MESSAGES` / `MP_MAX_AGE`) ne peut plus l'être : c'est la seule copie. Avec `MP_MAX_MESSAGES=1000` et `MP_MAX_AGE=7d`, cela n'arrive que si plus de 1000 mails arrivent pendant une panne de l'API, ou si elle dure plus de 7 jours (l'API supprimerait de toute façon ces mails au bout de 7 jours : `webhook.max_age_days`). Au retour de l'API, le relais commence par les plus anciens, les prochains à être supprimés.
- Un passage lit au plus 20 lots de `RELAY_BATCH_SIZE` mails (1000 avec la valeur par défaut). Au-delà, si tous les mails lus attendent leur nouvel essai, les plus récents attendent le passage où les plus anciens sont réessayés.
- Retirer le tag `relayed` d'un mail dans l'interface de Mailpit le fait renvoyer. C'est sans risque, car l'API le reconnaît comme un doublon.
- Le relais garde en mémoire seulement le délai avant le prochain essai de chaque mail en échec. Après un redémarrage, tous les mails en attente sont réessayés tout de suite.

## Tests

Tests **PHPUnit** du relais, sur votre ordinateur (PHP 8.4 minimum avec `curl` ; `posix` pour le test du signal envoyé par le récepteur, `pcntl` et `posix` pour ceux de `bin/relay.php`). Le relais reste **sans dépendance** : `composer.json` ne contient que PHPUnit, en `require-dev`, et le `Containerfile` ne copie que `bin/`, `public/` et `src/`. L'image est la même qu'avant.

```bash
cd relay
composer install
vendor/bin/phpunit
```

| Fichier | Ce qui est testé |
|---|---|
| `tests/ConfigTest.php` | variables `RELAY_…` : valeurs par défaut, chaque erreur (dont `RELAY_API_URL` en `http://` hors `localhost`), `relay.env.example` |
| `tests/HttpTest.php` | client HTTP contre un petit serveur `php -S` : en-têtes, authentification, taille maximale, délai, redirection non suivie |
| `tests/ReceiverTest.php` | récepteur (`public/index.php`) : `POST /hook`, `GET /health`, `405`, `404`, et le signal `SIGUSR1` qui réveille le worker |
| `tests/WorkerTest.php` | le relais de bout en bout (voir ci-dessous) |
| `tests/HeartbeatTest.php` | ping de santé contre les mêmes faux serveurs : latence du ping précédent, compteurs en attente / refusés, Mailpit injoignable (ping sans compteurs), API injoignable ou jeton refusé, journal (premier échec, rappel après 10 minutes, rétablissement) avec une horloge simulée |
| `tests/RelayProcessTest.php` | `bin/relay.php` lancé comme dans le conteneur : le ping de santé tourne à côté du Worker, son arrêt fait quitter le relais en erreur (récepteur arrêté aussi), `SIGTERM` arrête tout, `RELAY_HEARTBEAT_INTERVAL=0` ne le lance pas. Demande `pcntl` et `posix` (sinon : ignoré, « skipped ») |

Pour le Worker, les tests lancent deux faux serveurs `php -S 127.0.0.1:<port libre>`, qui partagent leur état dans un fichier temporaire :

- un **faux Mailpit** (`tests/Fixtures/mailpit-router.php`) : recherche (filtres `!tag:` et `tag:`), mail complet, en-têtes, pièces, `PUT` des tags et du « non lu » ;
- une **fausse API** (`tests/Fixtures/api-router.php`) : `POST /v1/messages`, `POST /v1/messages/{id}/part/{partId}` et `POST /v1/health`, avec contrôle du jeton et du SHA-256.

Le Worker tourne dans un processus séparé (`tests/Fixtures/run-worker.php`, chargé par `src/bootstrap.php` comme en production), et ses logs sont vérifiés. Cas couverts : livraison complète puis tag `relayed`, pièces envoyées avec le bon SHA-256, remise en « non lu », refus définitif (`422`, `409`) ⇒ tag `relay-failed`, erreur passagère (`500`, `401`) ⇒ pas de tag et nouvel essai différé, empreinte de Mailpit différente, pièces `too_large`, rattrapage d'un retard du plus ancien au plus récent sur plusieurs lots, lot le plus ancien entièrement en attente de nouvel essai (les suivants sont quand même relayés), mail arrivé pendant un passage (décalage repéré, nouveau passage aussitôt), Mailpit injoignable.

Aucun service externe n'est nécessaire : tout tourne sur `127.0.0.1`.

**CI** : à chaque `push` et `pull request`, GitHub Actions lance ces tests en PHP 8.5 avec `curl`, `pcntl` et `posix` (`.github/workflows/tests.yml`, job `relay`), ainsi que ceux de l'[API](../api/README.md#tests-automatisés).
