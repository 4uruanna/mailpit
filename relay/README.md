# Relais Mailpit → API

Petit service **PHP 8.5**, sans dépendance, qui tourne dans **Podman** sur le même serveur que Mailpit. Il transmet à l'[API](../api/README.md) le **contenu complet** de chaque mail, alors que le webhook de Mailpit n'envoie qu'un résumé.

```
Mailpit ── webhook (résumé) ──► relais ── met en file d'attente, répond 202 tout de suite
   ▲                              │
   └── GET /api/v1/message/{ID} ──┤  (texte, HTML, et .eml si RELAY_INCLUDE_RAW=true)
                                  ▼
                 POST https://api.mondomaine.fr/hook/mailpit  (résumé + Text + HTML [+ Raw])
```

- **File d'attente sur disque** (volume `/data`) : un mail n'est retiré qu'une fois accepté par l'API. Si l'API est injoignable, il est réessayé (30 s, 1 min, 2 min… puis toutes les heures, 48 essais par défaut). Après le dernier essai, il est rangé dans `failed/`.
- **Réponse immédiate à Mailpit** : le travail lent est fait en arrière-plan, bien avant le délai de 5 secondes de Mailpit.
- **État « non lu » conservé** : lire un mail via l'API de Mailpit le marque comme lu, le relais le remet en « non lu ».
- **Aucun port publié** : le relais n'est joignable que par les conteneurs du réseau Podman `mailpit`.

Installation pas à pas : **[Partie 7 du tutoriel](../docs/mailpit/07-relay.md)**.

## Fichiers

| Fichier | Rôle |
|---|---|
| `Containerfile` | image Podman (`php:8.5-cli-alpine` + extension `pcntl`) |
| `relay.env.example` | modèle de configuration (`/etc/mailpit/relay.env`) |
| `bin/relay.php` | point d'entrée : lance le récepteur et traite la file d'attente |
| `bin/status.php` | affiche la file d'attente et les mails abandonnés |
| `bin/retry-failed.php` | remet les mails abandonnés dans la file |
| `public/index.php` | récepteur HTTP (`POST /hook`, `GET /health`) |
| `src/` | code du relais |

## Configuration

Variables d'environnement (voir `relay.env.example`) :

| Variable | Défaut | Rôle |
|---|---|---|
| `RELAY_TARGET_URL` | *(obligatoire)* | URL de l'API, avec `?token=…` si `webhook.token` est configuré |
| `RELAY_MAILPIT_URL` | `http://mailpit:8025` | Mailpit vu depuis le conteneur |
| `RELAY_MAILPIT_AUTH` | *(aucun)* | identifiants `MP_UI_AUTH` au format `utilisateur:motdepasse` |
| `RELAY_INCLUDE_RAW` | `false` | joint le mail brut (`.eml`, pièces jointes comprises) en base64 |
| `RELAY_MAX_RAW_SIZE` | `5242880` | taille maximale du mail brut joint (octets) |
| `RELAY_MAX_ATTEMPTS` | `48` | nombre d'essais avant abandon |
| `RELAY_TIMEOUT` | `30` | délai maximal d'une requête HTTP (secondes) |
| `RELAY_KEEP_UNREAD` | `true` | remet en « non lu » les mails lus par le relais |

## Ce qui est envoyé à l'API

Le JSON reçu de Mailpit, inchangé, avec en plus :

```json
{
  "ID": "Wdyx4jVvBWELmoNePrh8Ff",
  "…": "…autres champs du résumé…",
  "Text": "Corps du mail en texte",
  "HTML": "<p>Corps du mail en HTML</p>",
  "Raw": "UmV0dXJuLVBhdGg6IDw…  (seulement si RELAY_INCLUDE_RAW=true)"
}
```

Si le mail a déjà disparu de Mailpit (supprimé, ou purgé par `MP_MAX_MESSAGES`) quand le relais le traite, seul le résumé est envoyé.

Codes de réponse de l'API : `2xx` → mail retiré de la file. `400`, `413`, `415`, `422` → abandon immédiat, car réessayer ne changerait rien. Tout autre code, y compris `403` (IP ou jeton), entraîne un nouvel essai plus tard : vous avez ainsi le temps de corriger la configuration.
