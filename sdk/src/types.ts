/**
 * Types publics du SDK : les réponses de l'API, en camelCase, avec les dates en `Date`.
 * Les dates de l'API sont en UTC à la milliseconde près : la conversion en `Date` ne perd rien.
 */

/** Une adresse e-mail, telle qu'elle figure dans un mail. */
export interface EmailAddress {
  /** Nom affiché (« Alice »), chaîne vide s'il n'y en a pas. */
  name: string;
  /** Adresse (« alice@exemple.fr »). */
  address: string;
}

/** `attachment` : pièce jointe. `inline` : image intégrée au HTML (`cid:…`). */
export type PartKind = 'attachment' | 'inline';

/**
 * `stored` : enregistrée par l'API. `pending` : pas encore reçue (le relais l'envoie après le mail).
 * `too_large` : au-delà de la limite de l'API, non enregistrée.
 */
export type PartStatus = 'stored' | 'pending' | 'too_large';

/** Description d'une pièce jointe ou d'une image intégrée. Son contenu n'est pas téléchargeable par l'API. */
export interface MessagePart {
  partId: string;
  kind: PartKind;
  fileName: string;
  contentType: string;
  /** Identifiant `cid:` d'une image intégrée, chaîne vide sinon. */
  contentId: string;
  /** Taille en octets. */
  size: number;
  status: PartStatus;
}

/** Résumé d'un mail, tel que renvoyé par la liste : sans corps ni en-têtes. */
export interface MessageSummary {
  /** ID du mail dans Mailpit : c'est lui qu'attendent `getMessage()` et `deleteMessage()`. */
  id: string;
  /** En-tête `Message-ID`. */
  messageId: string;
  from: EmailAddress | null;
  to: EmailAddress[];
  cc: EmailAddress[];
  bcc: EmailAddress[];
  replyTo: EmailAddress[];
  subject: string;
  /** Début du texte du mail, calculé par Mailpit : suffit parfois pour lire un code court. */
  snippet: string;
  returnPath: string;
  tags: string[];
  /** Taille du mail en octets. */
  size: number;
  /** Nombre de pièces jointes. */
  attachments: number;
  /** Utilisateur SMTP authentifié, ou `null`. */
  username: string | null;
  /** En-tête `Mailpit-Label` (`RELAY_LABEL` du relais), ou `null`. */
  label: string | null;
  /** En-tête `Date` du mail, ou `null` s'il est absent. */
  date: Date | null;
  /** Réception par Mailpit : c'est la date que compare `since`. */
  createdAt: Date;
  /** Réception par l'API. */
  receivedAt: Date;
  /** `true` quand toutes les pièces attendues sont enregistrées. */
  complete: boolean;
  /** `true` si le relais a raccourci le mail, trop volumineux pour l'API. */
  truncated: boolean;
  parts: MessagePart[];
}

/** Un mail complet : le résumé, plus son corps et ses en-têtes. */
export interface Message extends MessageSummary {
  /** Corps texte, ou `null` (mail reçu par l'ancien webhook direct de Mailpit). */
  text: string | null;
  /** Corps HTML, ou `null`. */
  html: string | null;
  /** En-têtes du mail : nom => liste de valeurs (`{}` si inconnus). */
  headers: Record<string, string[]>;
}

/** Une page de la liste des mails, du plus récent au plus ancien. */
export interface MessageList {
  /** Nombre de mails correspondants, toutes pages confondues. */
  total: number;
  /** Nombre de mails dans cette page. */
  count: number;
  limit: number;
  offset: number;
  page: number;
  /** Durée de l'attente côté API en millisecondes, avec l'option `wait` ; `null` sans. */
  waitedMs: number | null;
  messages: MessageSummary[];
}

/** Une adresse associée au jeton. */
export interface AddressInfo {
  address: string;
  /** Nombre de mails reçus par cette adresse. */
  messageCount: number;
}

export interface DeleteMessageResult {
  id: string;
  deleted: true;
}

export interface DeleteAddressMessagesResult {
  address: string;
  /** Nombre de mails supprimés. */
  deletedCount: number;
}

/** Options du client. */
export interface AmnesicMailClientOptions {
  /**
   * URL de l'API, sans `/v1` : `https://api.mondomaine.fr` (ou avec le `base_path` de l'installation,
   * `https://mondomaine.fr/mailpit-api`). `https://` obligatoire, sauf vers `localhost`.
   */
  baseUrl: string | URL;
  /** Jeton développeur `mpt_…`, créé dans le Dashboard. Il n'est envoyé que dans l'en-tête `Authorization`. */
  token: string;
  /**
   * Délai maximal d'une requête, en millisecondes (30 000 par défaut). Une requête qui attend un mail
   * (`wait`) a droit à ce délai en plus de son attente.
   */
  timeout?: number | undefined;
  /** Nombre de relances après une erreur réseau, 429 ou 5xx (2 par défaut, 0 pour aucune). */
  maxRetries?: number | undefined;
  /** Délai avant la première relance, en millisecondes, doublé à chaque essai (500 par défaut). */
  retryDelay?: number | undefined;
  /** Implémentation de `fetch` à utiliser (par défaut : `globalThis.fetch`, lu à chaque requête). */
  fetch?: typeof globalThis.fetch | undefined;
}

/** Options communes à toutes les méthodes. */
export interface RequestOptions {
  /** Pour annuler la requête (ou l'attente). */
  signal?: AbortSignal | undefined;
}

/** `Date`, ou chaîne ISO 8601 **avec fuseau** (`2026-11-09T14:03:00Z`). */
export type DateInput = Date | string;

export interface ListMessagesOptions extends RequestOptions {
  /** Une seule des adresses du jeton (par défaut : toutes). */
  address?: string | undefined;
  /** Nombre de mails, de 1 à 25 (5 par défaut côté API). */
  limit?: number | undefined;
  /** Nombre de mails à sauter. Incompatible avec `page`. */
  offset?: number | undefined;
  /** Numéro de page, à partir de 1. Incompatible avec `offset`. */
  page?: number | undefined;
  /** Seulement les mails reçus par Mailpit strictement après cette date. */
  since?: DateInput | null | undefined;
  /**
   * Attendre qu'au moins un mail **complet** corresponde à `address` et `since`, en **secondes**
   * (1 à 60). La réponse ne contient alors que des mails complets. Pour attendre plus longtemps, ou
   * obtenir directement le mail complet : `waitForMessage()`.
   */
  wait?: number | undefined;
}

export interface IterateMessagesOptions extends RequestOptions {
  address?: string | undefined;
  since?: DateInput | null | undefined;
  /** Mails par requête, de 1 à 25 (25 par défaut). */
  pageSize?: number | undefined;
}

export interface CheckpointOptions extends RequestOptions {
  address?: string | undefined;
}

export interface WaitForMessageOptions extends RequestOptions {
  /** Une seule des adresses du jeton (par défaut : toutes). */
  address?: string | undefined;
  /**
   * Seulement les mails reçus par Mailpit strictement après cette date. Prenez-la avec
   * `checkpoint()` **avant** de déclencher l'envoi. Sans `since`, un mail déjà présent compte.
   */
  since?: DateInput | null | undefined;
  /** Durée maximale de l'attente, en millisecondes (60 000 par défaut). Peut dépasser 60 s. */
  timeout?: number | undefined;
  /**
   * Ne retenir que les mails qui passent ce filtre (sujet, expéditeur…). Les mails écartés ne
   * sont plus proposés : l'attente continue jusqu'au suivant.
   */
  filter?: ((message: MessageSummary) => boolean | Promise<boolean>) | undefined;
  /**
   * Intervalle entre deux interrogations, en millisecondes (1 000 par défaut), quand l'API ne peut
   * pas attendre pour nous : mails déjà présents mais écartés par `filter`, jeton sans adresse.
   */
  pollInterval?: number | undefined;
}
