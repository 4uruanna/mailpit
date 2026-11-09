# @gutilab/amnesic-mail

SDK **Node.js** typé (TypeScript) pour l'[API Mailpit](../api/README.md) : avec un **jeton développeur** `mpt_…`, il lit, attend et supprime les mails de test reçus par les adresses associées au jeton.

Pensé pour les tests de bout en bout : déclencher une inscription, **attendre** le mail, y lire le **code** ou le **lien**.

```ts
import { AmnesicMailClient, extractCode } from '@gutilab/amnesic-mail';

const mail = new AmnesicMailClient({
  baseUrl: 'https://api.mondomaine.fr',
  token: process.env.AMNESIC_MAIL_TOKEN!,
});

const address = 'essai@test.mondomaine.fr';
const since = await mail.checkpoint({ address }); // 1. repère, AVANT l'envoi
await inscrire(address);                         // 2. votre application envoie le mail
const message = await mail.waitForMessage({ address, since }); // 3. attend le mail complet
const code = extractCode(message);               // « 482913 »
```

- Les 5 routes développeur de l'API, typées : lister, lire, supprimer un mail, lister ses adresses, vider une adresse.
- `waitForMessage()` : attend le prochain mail, au-delà de 60 s si besoin, et le renvoie en entier.
- `iterateMessages()` : parcourt toutes les pages avec `for await`.
- `extractCode()`, `extractLinks()` : code de confirmation et liens d'un mail.
- Relances automatiques (réseau, 429, 5xx), délai maximal par requête, annulation (`AbortSignal`).
- Aucune dépendance. ESM, Node.js 22 ou plus.

## Sommaire

- [Installation](#installation)
- [Le jeton](#le-jeton)
- [Créer le client](#créer-le-client)
- [Attendre un mail : `checkpoint()` et `waitForMessage()`](#attendre-un-mail--checkpoint-et-waitformessage)
- [Lire un code ou un lien](#lire-un-code-ou-un-lien)
- [Lister, lire et supprimer](#lister-lire-et-supprimer)
- [Les objets renvoyés](#les-objets-renvoyés)
- [Erreurs](#erreurs)
- [Relances, délais et annulation](#relances-délais-et-annulation)
- [Exemple : un test Playwright](#exemple--un-test-playwright)
- [CommonJS et TypeScript](#commonjs-et-typescript)
- [Développer le SDK](#développer-le-sdk)
- [Publier sur npm](#publier-sur-npm)

## Installation

```bash
npm install --save-dev @gutilab/amnesic-mail
```

Node.js **22** ou plus (`fetch` intégré).

## Le jeton

Un administrateur crée le jeton dans le Dashboard de l'API (**Jetons**), et y associe les adresses de test du développeur (voir [Jetons développeurs](../api/README.md#jetons-développeurs)). Le jeton ne voit que les mails dont l'un des destinataires (`To`, `Cc` ou `Bcc`) est une de ses adresses.

Gardez-le hors du code, dans une variable d'environnement ou le gestionnaire de secrets de votre CI :

```bash
AMNESIC_MAIL_TOKEN=mpt_…
```

Le SDK ne l'envoie que dans l'en-tête `Authorization: Bearer …`, jamais dans l'URL, et ne l'écrit dans aucun message d'erreur. Il le garde dans un champ privé : `console.log(mail)` ne l'affiche pas.

## Créer le client

```ts
const mail = new AmnesicMailClient({
  baseUrl: 'https://api.mondomaine.fr', // obligatoire
  token: process.env.AMNESIC_MAIL_TOKEN!, // obligatoire
});
```

| Option | Défaut | Rôle |
|---|---|---|
| `baseUrl` | — | URL de l'API, **sans** `/v1`. Avec le `base_path` de l'installation s'il y en a un : `https://mondomaine.fr/mailpit-api`. `https://` obligatoire : `http://` n'est accepté que vers `localhost` (API lancée en local avec `force_https => false`) |
| `token` | — | Jeton `mpt_…`. Les espaces autour sont retirés (fichier `.env`) |
| `timeout` | `30000` | Délai maximal d'une requête, en ms. Une requête qui attend un mail a droit à ce délai **en plus** de son attente |
| `maxRetries` | `2` | Relances après une erreur réseau, `408`, `429` ou `5xx`. `0` : aucune |
| `retryDelay` | `500` | Délai avant la première relance, en ms, doublé à chaque essai (8 s au plus) |
| `fetch` | `globalThis.fetch` | Autre implémentation de `fetch` (proxy, tests). Sans cette option, `globalThis.fetch` est relu à chaque requête : les outils qui le remplacent (MSW…) fonctionnent même s'ils démarrent après le client |

Une option invalide lève une `TypeError` ou une `RangeError` dès la construction.

## Attendre un mail : `checkpoint()` et `waitForMessage()`

Le scénario type : **noter un repère**, **déclencher l'envoi**, **attendre**.

```ts
const since = await mail.checkpoint({ address });
await page.getByRole('button', { name: 'Recevoir un code' }).click();
const message = await mail.waitForMessage({ address, since, timeout: 90_000 });
```

**`checkpoint({ address? })`** renvoie la date de réception (par Mailpit) du mail le plus récent de l'adresse, ou `null` s'il n'y en a aucun. Passée en `since`, elle fait ignorer tous les mails déjà là. Préférez-la à `new Date()` : `since` est comparé à l'horloge du **serveur Mailpit**, et une machine de test en avance de quelques secondes ferait ignorer le mail attendu.

**`waitForMessage(options)`** renvoie le prochain mail **complet** (toutes ses pièces jointes reçues par l'API), en entier : texte, HTML, en-têtes.

| Option | Défaut | Rôle |
|---|---|---|
| `address` | toutes les adresses du jeton | une seule de ses adresses |
| `since` | aucun | seulement les mails reçus **strictement après** cette date (`Date` ou texte ISO 8601 avec fuseau). **Sans `since`, un mail déjà présent compte** : utilisez `checkpoint()`, ou une adresse neuve à chaque test |
| `timeout` | `60000` | durée maximale de l'attente, en ms. Peut dépasser 60 s |
| `filter` | aucun | `(résumé) => boolean` (ou une promesse) : ne retenir que les mails qui passent ce test |
| `pollInterval` | `1000` | intervalle entre deux requêtes quand l'API ne peut pas attendre pour nous (voir plus bas), en ms |
| `signal` | aucun | `AbortSignal` pour abandonner l'attente |

Si rien n'arrive à temps : `WaitTimeoutError`.

Comment ça marche :

- **Pas de boucle d'interrogation.** Le SDK utilise le paramètre `wait` de l'API : la requête reste ouverte jusqu'à l'arrivée d'un mail, 60 s au plus. Pour un `timeout` plus long, il enchaîne ces attentes.
- **Plusieurs mails ?** C'est le **plus ancien** après `since` qui est renvoyé. Pour attendre le suivant, passez son `createdAt` comme nouveau `since` :

  ```ts
  const bienvenue = await mail.waitForMessage({ address, since });
  const confirmation = await mail.waitForMessage({ address, since: bienvenue.createdAt });
  ```

- **`filter`** reçoit le résumé du mail (sujet, expéditeur, extrait…), pas son corps. Un mail écarté n'est plus proposé. Tant que des mails écartés sont présents, l'API répond sans attendre : le SDK l'interroge alors toutes les `pollInterval` ms.

  ```ts
  const message = await mail.waitForMessage({
    address,
    since,
    filter: (m) => m.subject.includes('Réinitialisation'),
  });
  ```

- **Mails incomplets.** Le relais envoie un mail, puis ses pièces jointes une par une : un mail n'est renvoyé que lorsqu'il est complet (`complete: true`).

> ⚠️ Chaque attente occupe un processus PHP et une connexion MySQL sur l'hébergement de l'API (voir [Attendre un mail](../api/README.md#attendre-un-mail--wait-et-since)). Évitez des dizaines d'attentes en parallèle.

## Lire un code ou un lien

Fonctions pures, sans requête : elles prennent un `Message` (mail complet), un `MessageSummary`, ou directement un texte.

```ts
import { extractCode, extractLink, extractLinks } from '@gutilab/amnesic-mail';

extractCode(message);                                  // premier nombre de 6 chiffres isolé, ou null
extractCode(message, { length: 4 });                   // un PIN à 4 chiffres
extractCode(message, { length: { min: 4, max: 8 } });  // entre 4 et 8 chiffres
extractCode(message, { pattern: /code : ([A-Z0-9]{8})/i }); // premier groupe capturant

extractLinks(message);                                 // tous les liens http(s), sans doublon
extractLinks(message, { filter: '/reset-password' });  // qui contiennent ce texte
extractLink(message, { filter: /\/confirmer\?token=/ }); // le premier qui correspond, ou null
```

- **`extractCode`** cherche dans le texte, puis le HTML (sans les styles ni les commentaires, où traînent des couleurs comme `#123456`), puis l'extrait, puis le sujet. Un nombre plus long (`0612345678`) n'est pas coupé pour en tirer 6 chiffres. Avec un simple résumé de la liste, l'extrait (`snippet`) suffit souvent pour un code court.
- **`extractLinks`** lit les `href` du HTML (entités décodées : `&amp;` → `&`), puis les URL du texte (sans la ponctuation collée : « …/reset. »). `filter` : texte contenu, expression régulière ou fonction. Il faut le mail complet : l'extrait d'un résumé est tronqué.

## Lister, lire et supprimer

Toutes les méthodes acceptent `{ signal }` en dernier argument.

| Méthode | Route | Renvoie |
|---|---|---|
| `listMessages(options?)` | `GET /v1/messages` | `MessageList` : une page de **résumés**, du plus récent au plus ancien |
| `iterateMessages(options?)` | `GET /v1/messages`, page après page | `AsyncGenerator<MessageSummary>` |
| `getMessage(id)` | `GET /v1/messages/{id}` | `Message` : le mail complet |
| `deleteMessage(id)` | `DELETE /v1/messages/{id}` | `{ id, deleted: true }` |
| `listAddresses()` | `GET /v1/addresses` | `AddressInfo[]` : `{ address, messageCount }` |
| `deleteAddressMessages(address)` | `DELETE /v1/addresses?address=…` | `{ address, deletedCount }` |
| `checkpoint(options?)` | `GET /v1/messages?limit=1` | `Date \| null` |
| `waitForMessage(options?)` | `GET /v1/messages?wait=…`, puis `GET /v1/messages/{id}` | `Message` |

**`listMessages`** reprend les paramètres de l'API : `address`, `limit` (1 à 25, 5 par défaut), `offset` **ou** `page`, `since`, `wait` (en **secondes**, 1 à 60 : la réponse ne contient alors que des mails complets, et `waitedMs` la durée de l'attente).

```ts
const { total, messages } = await mail.listMessages({ address, limit: 25, page: 2 });
```

**`iterateMessages`** (`address`, `since`, `pageSize` de 1 à 25, 25 par défaut) parcourt toutes les pages. Un mail arrivé pendant le parcours décale les pages : il n'est pas renvoyé deux fois.

```ts
for await (const summary of mail.iterateMessages({ address })) {
  console.log(summary.createdAt, summary.subject);
}
```

**Supprimer** : le mail disparaît de l'API (base et pièces jointes) pour **toutes** ses adresses, y compris pour les autres jetons qui le voyaient ; il reste dans Mailpit. `deleteAddressMessages` ne retire pas l'adresse du jeton. Pratique avant un test :

```ts
await mail.deleteAddressMessages(address); // repartir d'une boîte vide
```

## Les objets renvoyés

Les champs de l'API, en camelCase, avec les dates en `Date` (UTC, à la milliseconde : rien n'est perdu).

```ts
interface MessageSummary {
  id: string;               // ID Mailpit : pour getMessage() et deleteMessage()
  messageId: string;        // en-tête Message-ID
  from: EmailAddress | null; // { name, address }
  to: EmailAddress[]; cc: EmailAddress[]; bcc: EmailAddress[]; replyTo: EmailAddress[];
  subject: string;
  snippet: string;          // début du texte, calculé par Mailpit
  returnPath: string;
  tags: string[];
  size: number;             // octets
  attachments: number;
  username: string | null;  // utilisateur SMTP authentifié
  label: string | null;     // en-tête Mailpit-Label
  date: Date | null;        // en-tête Date
  createdAt: Date;          // réception par Mailpit : c'est ce que compare since
  receivedAt: Date;         // réception par l'API
  complete: boolean;        // toutes les pièces jointes reçues
  truncated: boolean;       // raccourci par le relais (mail trop volumineux pour l'API)
  parts: MessagePart[];     // { partId, kind, fileName, contentType, contentId, size, status }
}

interface Message extends MessageSummary {
  text: string | null;
  html: string | null;
  headers: Record<string, string[]>; // nom => valeurs
}
```

Le contenu des pièces jointes n'est pas téléchargeable par l'API : seule leur description l'est (`parts`, avec `status` : `stored`, `pending` ou `too_large`).

## Erreurs

Toutes les erreurs du SDK héritent d'`AmnesicMailError` :

| Classe | Quand | Propriétés utiles |
|---|---|---|
| `ApiError` | l'API répond avec un code d'erreur | `status`, `apiMessage` (message de l'API), `body`, `method`, `path`, `attempts` |
| ├ `BadRequestError` | `400` : paramètre invalide (`limit` hors bornes, `since` sans fuseau…) | |
| ├ `AuthenticationError` | `401` : jeton absent, inconnu ou supprimé | |
| ├ `PermissionDeniedError` | `403` : adresse non associée au jeton | |
| └ `NotFoundError` | `404` : mail inconnu, ou reçu par aucune adresse du jeton (l'API ne distingue pas) | |
| `ConnectionError` | API injoignable (DNS, connexion refusée, certificat…), relances épuisées | `cause` |
| `RequestTimeoutError` | pas de réponse dans le délai `timeout` | `timeout` |
| `WaitTimeoutError` | `waitForMessage()` : aucun mail à temps | `timeout` |

```ts
import { NotFoundError, WaitTimeoutError } from '@gutilab/amnesic-mail';

try {
  await mail.getMessage(id);
} catch (error) {
  if (error instanceof NotFoundError) { /* supprimé, ou pas pour vos adresses */ }
  throw error;
}
```

Le message d'erreur dit ce qui s'est passé, sans jamais contenir le jeton :

```
GET /v1/messages?address=autre%40test.mondomaine.fr : HTTP 403 – l'adresse « autre@test.mondomaine.fr » n'est pas associée à ce jeton
```

Une redirection n'est jamais suivie (`ApiError` `3xx`) : elle signale presque toujours une `baseUrl` à corriger.

## Relances, délais et annulation

- **Relances** : après une erreur réseau, `408`, `429`, `500`, `502`, `503` ou `504`, jusqu'à `maxRetries` fois, avec un délai qui double à chaque essai (avec une part d'aléatoire). Un en-tête `Retry-After` est respecté (30 s au plus). Jamais après les autres `4xx` : la même requête recevrait la même réponse. Ni après un délai dépassé : l'attente a déjà consommé le temps prévu.
- **`deleteMessage`** relancé après une réponse perdue : si l'API répond alors `404`, le mail n'existe plus (l'essai précédent l'a sans doute supprimé) et le résultat est `deleted: true`.
- **Délai** : `timeout` par requête (corps compris). Une requête avec `wait` y ajoute son attente.
- **Annulation** : passez un `AbortSignal` ; la promesse est rejetée avec la raison du signal, sans relance.

```ts
await mail.waitForMessage({ address, since, signal: AbortSignal.timeout(120_000) });
```

## Exemple : un test Playwright

```ts
import { expect, test } from '@playwright/test';
import { AmnesicMailClient, extractCode, extractLink } from '@gutilab/amnesic-mail';

const mail = new AmnesicMailClient({
  baseUrl: process.env.AMNESIC_MAIL_URL!,
  token: process.env.AMNESIC_MAIL_TOKEN!,
});
const address = 'essai@test.mondomaine.fr';

test('mot de passe oublié', async ({ page }) => {
  const since = await mail.checkpoint({ address });

  await page.goto('/mot-de-passe-oublie');
  await page.getByLabel('E-mail').fill(address);
  await page.getByRole('button', { name: 'Envoyer' }).click();

  const message = await mail.waitForMessage({
    address,
    since,
    filter: (m) => m.subject.includes('Réinitialisation'),
  });
  const link = extractLink(message, { filter: '/reinitialiser' });
  expect(link).not.toBeNull();
  await page.goto(link!);
});
```

Pour des tests en parallèle, donnez à chacun **sa propre adresse** (toutes associées au jeton) : leurs mails ne se mélangent pas.

## CommonJS et TypeScript

Le paquet est en **ESM** seul.

- Projet ESM (`"type": "module"`, `.mjs`) : `import { AmnesicMailClient } from '@gutilab/amnesic-mail'`.
- Projet CommonJS : `require('@gutilab/amnesic-mail')` fonctionne à partir de Node.js **22.12** (`require` d'un module ESM). Sinon : `const { AmnesicMailClient } = await import('@gutilab/amnesic-mail')`.
- TypeScript : `"moduleResolution": "nodenext"` (ou `"bundler"`). Avec `"module": "node16"`, un fichier CommonJS ne peut pas importer le paquet : passez à `"nodenext"`.

Les déclarations de types (`.d.ts`) sont incluses, avec les sources (`src/`) pour « Aller à la définition ».

## Développer le SDK

```
sdk/
├── src/
│   ├── index.ts      # exports publics
│   ├── client.ts     # AmnesicMailClient : routes, waitForMessage, iterateMessages
│   ├── http.ts       # requêtes : en-têtes, délai, relances, erreurs
│   ├── mappers.ts    # réponses de l'API (snake_case) → objets du SDK (camelCase, Date)
│   ├── extract.ts    # extractCode, extractLinks
│   ├── errors.ts     # classes d'erreurs
│   ├── types.ts      # types publics
│   └── version.ts    # version (en-tête User-Agent)
├── tests/            # Vitest ; support/fake-api.ts reproduit les règles de l'API
├── tsconfig.json     # vérification des types (src + tests)
└── tsconfig.build.json # compilation src/ → dist/
```

```bash
cd sdk
npm ci                 # dépendances (TypeScript, Vitest, @types/node)
npm test               # tests (Vitest)
npm run test:watch     # tests en continu
npm run typecheck      # types de src/ et tests/
npm run build          # dist/ : JavaScript ESM + .d.ts
```

Les tests n'appellent jamais la vraie API : `tests/support/fake-api.ts` est une fausse API en mémoire, branchée à la place de `fetch`, qui reproduit les règles des routes développeur (`api/src/Action/Api/`) : portée du jeton, tri, `since` à la milliseconde, `wait` sur les mails complets. Une évolution de ces routes doit y être reportée, ainsi que dans `src/mappers.ts`.

Les tests tournent dans GitHub Actions à chaque `push` (job **SDK** de `.github/workflows/tests.yml`, Node.js 22 et 24).

## Publier sur npm

1. Changez la version dans **`package.json` et `src/version.ts`** (un test vérifie qu'elles sont égales).
2. Connectez-vous à un compte npm qui peut publier dans le scope `@gutilab` : `npm login`.
3. Publiez depuis `sdk/` :

   ```bash
   npm publish
   ```

   `prepublishOnly` vérifie les types, lance les tests et compile `dist/` avant l'envoi. `publishConfig.access` vaut `public` : un paquet à scope serait privé sinon.

Pour vérifier le contenu sans publier : `npm pack --dry-run`.
