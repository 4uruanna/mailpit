import { NotFoundError, WaitTimeoutError } from './errors.js';
import { HttpClient, sleep } from './http.js';
import {
  toAddressList,
  toMessage,
  toMessageList,
  type RawAddressList,
  type RawDeleteAddressMessages,
  type RawDeleteMessage,
  type RawMessage,
  type RawMessageList,
} from './mappers.js';
import type {
  AddressInfo,
  AmnesicMailClientOptions,
  CheckpointOptions,
  DateInput,
  DeleteAddressMessagesResult,
  DeleteMessageResult,
  IterateMessagesOptions,
  ListMessagesOptions,
  Message,
  MessageList,
  MessageSummary,
  RequestOptions,
  WaitForMessageOptions,
} from './types.js';

/** Nombre maximal de mails par page (limite de l'API). */
export const MAX_PAGE_SIZE = 25;
/** Attente maximale d'une requête `wait`, en secondes (limite de l'API). */
export const MAX_WAIT_SECONDS = 60;

const DEFAULT_TIMEOUT = 30_000;
const DEFAULT_MAX_RETRIES = 2;
const DEFAULT_RETRY_DELAY = 500;
const DEFAULT_WAIT_TIMEOUT = 60_000;
const DEFAULT_POLL_INTERVAL = 1_000;

/** Filtres d'une recherche de mails, communs aux requêtes d'une même attente. */
interface SearchQuery extends RequestOptions {
  address?: string | undefined;
  since?: DateInput | undefined;
  wait?: number | undefined;
}

/**
 * Client de l'API Mailpit pour les développeurs : lit, attend et supprime les mails reçus par les
 * adresses associées à un jeton `mpt_…` (créé dans le Dashboard).
 *
 * ```ts
 * const mail = new AmnesicMailClient({ baseUrl: 'https://api.mondomaine.fr', token: process.env.AMNESIC_MAIL_TOKEN! });
 * const since = await mail.checkpoint({ address });
 * await inscrire(address);
 * const message = await mail.waitForMessage({ address, since });
 * ```
 */
export class AmnesicMailClient {
  /** URL de base de l'API, sans « / » final. */
  readonly baseUrl: string;
  // Le jeton reste dans un champ privé : ni console.log ni JSON.stringify ne l'affichent.
  readonly #http: HttpClient;

  constructor(options: AmnesicMailClientOptions) {
    if (typeof options !== 'object' || options === null) {
      throw new TypeError('AmnesicMailClient : options { baseUrl, token } manquantes');
    }
    this.baseUrl = normalizeBaseUrl(options.baseUrl);
    this.#http = new HttpClient({
      baseUrl: this.baseUrl,
      token: normalizeToken(options.token),
      timeout: numberOption(options.timeout, DEFAULT_TIMEOUT, 'timeout', 1),
      maxRetries: integerOption(options.maxRetries, DEFAULT_MAX_RETRIES, 'maxRetries'),
      retryDelay: numberOption(options.retryDelay, DEFAULT_RETRY_DELAY, 'retryDelay', 0),
      fetch: options.fetch,
    });
  }

  /**
   * `GET /v1/messages` : une page de résumés (sans corps ni en-têtes), du plus récent au plus ancien.
   *
   * @throws {BadRequestError} paramètre invalide
   * @throws {PermissionDeniedError} `address` n'est pas associée au jeton
   */
  async listMessages(options: ListMessagesOptions = {}): Promise<MessageList> {
    const wait = options.wait;
    const { data } = await this.#http.request<RawMessageList>({
      method: 'GET',
      path: '/v1/messages',
      query: {
        address: options.address,
        limit: options.limit,
        offset: options.offset,
        page: options.page,
        since: sinceParam(options.since),
        wait,
      },
      signal: options.signal,
      extraTimeout: wait !== undefined && Number.isFinite(wait) && wait > 0 ? wait * 1000 : 0,
    });

    return toMessageList(data);
  }

  /**
   * Parcourt tous les mails (résumés), du plus récent au plus ancien, page par page :
   *
   * ```ts
   * for await (const summary of mail.iterateMessages({ address })) { … }
   * ```
   *
   * Un mail arrivé pendant le parcours décale les pages : il n'est pas renvoyé deux fois. Un mail
   * supprimé pendant le parcours peut en faire sauter un autre.
   */
  async *iterateMessages(options: IterateMessagesOptions = {}): AsyncGenerator<MessageSummary, void, undefined> {
    const limit = options.pageSize ?? MAX_PAGE_SIZE;
    const seen = new Set<string>();

    for (let offset = 0; ; offset += limit) {
      const page = await this.listMessages({ address: options.address, since: options.since, limit, offset, signal: options.signal });
      for (const message of page.messages) {
        if (!seen.has(message.id)) {
          seen.add(message.id);
          yield message;
        }
      }
      if (page.messages.length < limit || offset + limit >= page.total) {
        return;
      }
    }
  }

  /**
   * `GET /v1/messages/{id}` : un mail complet (corps texte et HTML, en-têtes).
   *
   * @throws {NotFoundError} mail inconnu, ou reçu par aucune des adresses du jeton
   */
  async getMessage(id: string, options: RequestOptions = {}): Promise<Message> {
    const { data } = await this.#http.request<RawMessage>({ method: 'GET', path: messagePath(id), signal: options.signal });

    return toMessage(data);
  }

  /**
   * `DELETE /v1/messages/{id}` : supprime le mail de l'API (pas de Mailpit), pour toutes ses adresses.
   *
   * Après une relance (réponse perdue, erreur 5xx), un 404 veut dire que le mail n'existe plus :
   * l'essai précédent l'a sans doute supprimé. Le résultat est alors `deleted: true`.
   *
   * @throws {NotFoundError} mail inconnu, ou reçu par aucune des adresses du jeton
   */
  async deleteMessage(id: string, options: RequestOptions = {}): Promise<DeleteMessageResult> {
    try {
      const { data } = await this.#http.request<RawDeleteMessage>({ method: 'DELETE', path: messagePath(id), signal: options.signal });

      return { id: data.id, deleted: true };
    } catch (error) {
      if (error instanceof NotFoundError && error.attempts > 1) {
        return { id, deleted: true };
      }
      throw error;
    }
  }

  /** `GET /v1/addresses` : les adresses du jeton, avec leur nombre de mails. */
  async listAddresses(options: RequestOptions = {}): Promise<AddressInfo[]> {
    const { data } = await this.#http.request<RawAddressList>({ method: 'GET', path: '/v1/addresses', signal: options.signal });

    return toAddressList(data);
  }

  /**
   * `DELETE /v1/addresses?address=…` : supprime tous les mails reçus par cette adresse (elle reste
   * associée au jeton). Pratique avant un test.
   *
   * @throws {PermissionDeniedError} adresse non associée au jeton
   */
  async deleteAddressMessages(address: string, options: RequestOptions = {}): Promise<DeleteAddressMessagesResult> {
    if (typeof address !== 'string' || address.trim() === '') {
      throw new TypeError('deleteAddressMessages : adresse manquante');
    }
    const { data } = await this.#http.request<RawDeleteAddressMessages>({
      method: 'DELETE',
      path: '/v1/addresses',
      query: { address },
      signal: options.signal,
    });

    return { address: data.address, deletedCount: data.deleted };
  }

  /**
   * Repère pour `since`, à prendre **avant** de déclencher l'envoi : la date de réception (par
   * Mailpit) du mail le plus récent, ou `null` s'il n'y en a aucun. Contrairement à `new Date()`,
   * elle ne dépend pas de l'horloge de la machine qui lance le test.
   */
  async checkpoint(options: CheckpointOptions = {}): Promise<Date | null> {
    const list = await this.listMessages({ address: options.address, limit: 1, signal: options.signal });

    return list.messages[0]?.createdAt ?? null;
  }

  /**
   * Attend le prochain mail **complet** (pièces jointes reçues) et le renvoie en entier.
   *
   * L'API garde la requête ouverte jusqu'à l'arrivée d'un mail (60 s au plus) : le SDK enchaîne ces
   * attentes jusqu'à `timeout`. S'il y a plusieurs mails, c'est le plus ancien après `since` qui est
   * renvoyé : pour attendre le suivant, passez son `createdAt` comme nouveau `since`.
   *
   * @throws {WaitTimeoutError} aucun mail correspondant à temps
   */
  async waitForMessage(options: WaitForMessageOptions = {}): Promise<Message> {
    const timeout = numberOption(options.timeout, DEFAULT_WAIT_TIMEOUT, 'timeout', 0);
    const pollInterval = numberOption(options.pollInterval, DEFAULT_POLL_INTERVAL, 'pollInterval', 0);
    const since = options.since ?? undefined;
    sinceParam(since); // date invalide : erreur tout de suite, pas après la première requête
    const deadline = Date.now() + timeout;
    // Mails écartés par le filtre (ou supprimés entre la liste et la lecture) : plus proposés.
    const rejected = new Set<string>();

    for (;;) {
      const started = Date.now();
      const remaining = deadline - started;
      // Une seule vérification, sans attente, une fois le délai écoulé.
      const wait = remaining > 0 ? Math.min(MAX_WAIT_SECONDS, Math.max(1, Math.ceil(remaining / 1000))) : undefined;

      const message = await this.#findNext({ address: options.address, since, wait, signal: options.signal }, options.filter, rejected);
      if (message !== null) {
        return message;
      }

      const now = Date.now();
      if (remaining <= 0 || now >= deadline) {
        throw new WaitTimeoutError(describeWait(options, timeout, rejected.size), timeout);
      }
      // L'API a répondu sans attendre (mails écartés par le filtre, jeton sans adresse…) :
      // pas plus d'une requête par pollInterval.
      const pause = Math.min(pollInterval - (now - started), deadline - now);
      if (pause > 0) {
        await sleep(pause, options.signal);
      }
    }
  }

  /** Le plus ancien mail complet qui correspond à la recherche et au filtre, en entier, ou `null`. */
  async #findNext(
    query: SearchQuery,
    filter: WaitForMessageOptions['filter'],
    rejected: Set<string>,
  ): Promise<Message | null> {
    for await (const summary of this.#oldestFirst(query)) {
      if (!summary.complete || rejected.has(summary.id)) {
        continue;
      }
      if (filter !== undefined && !(await filter(summary))) {
        rejected.add(summary.id);
        continue;
      }
      try {
        return await this.getMessage(summary.id, { signal: query.signal });
      } catch (error) {
        // Supprimé entre la liste et la lecture : on passe au suivant.
        if (error instanceof NotFoundError) {
          rejected.add(summary.id);
          continue;
        }
        throw error;
      }
    }

    return null;
  }

  /**
   * Résumés des mails de la recherche, du plus ancien au plus récent. L'API les liste dans l'autre
   * sens : au-delà d'une page, on lit les pages en partant de la fin.
   */
  async *#oldestFirst(query: SearchQuery): AsyncGenerator<MessageSummary, void, undefined> {
    const first = await this.listMessages({ ...query, limit: MAX_PAGE_SIZE });
    if (first.total <= first.messages.length) {
      yield* first.messages.toReversed();

      return;
    }

    // Avec wait, l'API ne compte que les mails complets : on le garde pour les pages suivantes
    // (1 s : des mails sont déjà là, la réponse est immédiate).
    const next = { ...query, wait: query.wait === undefined ? undefined : 1 };
    for (let end = first.total; end > 0; end -= MAX_PAGE_SIZE) {
      const offset = Math.max(0, end - MAX_PAGE_SIZE);
      const page = await this.listMessages({ ...next, limit: end - offset, offset });
      yield* page.messages.toReversed();
    }
  }
}

function normalizeBaseUrl(value: string | URL): string {
  let url: URL;
  try {
    url = new URL(value);
  } catch {
    throw new TypeError('baseUrl invalide : URL complète attendue, par exemple https://api.mondomaine.fr');
  }

  const local = ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname) || url.hostname.endsWith('.localhost');
  if (url.protocol !== 'https:' && !(url.protocol === 'http:' && local)) {
    throw new TypeError(`baseUrl doit commencer par https:// (http:// seulement vers localhost) : ${url.protocol}//${url.host}`);
  }
  if (url.username !== '' || url.password !== '') {
    throw new TypeError('baseUrl ne doit pas contenir d\'identifiants : le jeton suffit');
  }
  if (url.search !== '' || url.hash !== '') {
    throw new TypeError('baseUrl ne doit contenir ni paramètres (?…) ni ancre (#…)');
  }

  return url.origin + url.pathname.replace(/\/+$/, '');
}

function normalizeToken(value: unknown): string {
  const token = typeof value === 'string' ? value.trim() : '';
  if (token === '') {
    throw new TypeError('token manquant : jeton développeur « mpt_… » créé dans le Dashboard');
  }
  if (/\s/.test(token)) {
    throw new TypeError('token invalide : il ne doit pas contenir d\'espace (sans « Bearer »)');
  }

  return token;
}

function numberOption(value: number | undefined, fallback: number, name: string, min: number): number {
  if (value === undefined) {
    return fallback;
  }
  if (typeof value !== 'number' || !Number.isFinite(value) || value < min) {
    throw new RangeError(`${name} doit être un nombre supérieur ou égal à ${min} (millisecondes)`);
  }

  return value;
}

function integerOption(value: number | undefined, fallback: number, name: string): number {
  if (value === undefined) {
    return fallback;
  }
  if (!Number.isInteger(value) || value < 0) {
    throw new RangeError(`${name} doit être un entier positif ou nul`);
  }

  return value;
}

/** `since` pour l'URL : une `Date` en ISO 8601 UTC (à la milliseconde), une chaîne telle quelle. */
function sinceParam(value: DateInput | null | undefined): string | undefined {
  if (value === null || value === undefined) {
    return undefined;
  }
  if (value instanceof Date) {
    if (Number.isNaN(value.getTime())) {
      throw new TypeError('since : date invalide');
    }

    return value.toISOString();
  }

  return value;
}

function messagePath(id: string): string {
  if (typeof id !== 'string' || id === '') {
    throw new TypeError('id du mail manquant');
  }

  return `/v1/messages/${encodeURIComponent(id)}`;
}

function describeWait(options: WaitForMessageOptions, timeout: number, rejected: number): string {
  const since = options.since ?? null;
  const parts = [
    'aucun mail complet',
    options.address === undefined ? null : `pour ${options.address}`,
    since === null ? null : `après ${since instanceof Date ? since.toISOString() : since}`,
    `en ${timeout} ms`,
    rejected === 0 || options.filter === undefined ? null : `(${rejected} mail(s) écarté(s) par le filtre)`,
  ];

  return parts.filter((part) => part !== null).join(' ');
}
