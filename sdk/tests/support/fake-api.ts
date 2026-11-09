import type { RawMessage, RawMessageSummary } from '../../src/mappers.js';
import { rawMessage } from './fixtures.js';

export interface RecordedRequest {
  method: string;
  path: string;
  query: URLSearchParams;
  headers: Headers;
}

interface StoredMessage {
  raw: RawMessage;
  /** Ordre d'arrivée dans l'API (départage les created_at égaux, comme l'id en base). */
  seq: number;
}

/**
 * Fausse API en mémoire, branchée à la place de `fetch`. Elle reproduit les règles des routes
 * développeur de api/src/Action/Api/ :
 *  - jeton dans « Authorization: Bearer … », sinon 401 ;
 *  - un jeton ne voit que les mails dont un destinataire (To, Cc, Bcc) est une de ses adresses ;
 *  - liste du plus récent au plus ancien (created_at puis ordre d'arrivée), limit 1 à 25 (5 par défaut) ;
 *  - since : created_at strictement après, à la milliseconde ;
 *  - wait : attend un mail complet, et ne liste alors que les mails complets.
 *
 * `waitScale` : durée d'une seconde de `wait`, en millisecondes (les tests la raccourcissent).
 */
export class FakeApi {
  readonly token = 'mpt_test_0123456789';
  readonly requests: RecordedRequest[] = [];
  /** Adresses associées au jeton. */
  addresses: string[];
  waitScale: number;
  /** Appelé après chaque requête, avant la réponse (pour simuler une arrivée pendant un parcours). */
  onRequest: ((request: RecordedRequest) => void) | null = null;

  #messages: StoredMessage[] = [];
  #seq = 0;
  #listeners = new Set<() => void>();
  #clock = Date.parse('2026-11-09T08:00:00.000Z');

  constructor(options: { addresses?: string[]; waitScale?: number } = {}) {
    this.addresses = options.addresses ?? ['essai@test.mondomaine.fr', 'alice@test.mondomaine.fr'];
    this.waitScale = options.waitScale ?? 1000;
  }

  /**
   * Ajoute un mail. Sa date de réception (created_at) avance d'une seconde à chaque mail, sauf si
   * elle est fournie. Avec `delay`, il n'arrive qu'après ce délai (en millisecondes).
   */
  add(overrides: Partial<RawMessage> = {}, options: { delay?: number } = {}): RawMessage {
    this.#clock += 1000;
    const id = overrides.id ?? `msg${String(this.#seq + 1).padStart(4, '0')}`;
    const raw = rawMessage({ id, created_at: new Date(this.#clock).toISOString(), parts: [], attachments: 0, ...overrides });
    const store = (): void => {
      this.#messages.push({ raw, seq: ++this.#seq });
      this.#notify();
    };
    if (options.delay === undefined) {
      store();
    } else {
      setTimeout(store, options.delay);
    }

    return raw;
  }

  /** Marque un mail comme complet (toutes ses pièces reçues), après `delay` millisecondes. */
  completeLater(id: string, delay: number): void {
    setTimeout(() => {
      const message = this.#messages.find((stored) => stored.raw.id === id);
      if (message !== undefined) {
        message.raw = { ...message.raw, complete: true };
        this.#notify();
      }
    }, delay);
  }

  get messages(): RawMessage[] {
    return this.#messages.map((stored) => stored.raw);
  }

  /** Requêtes reçues sur un chemin donné. */
  requestsTo(method: string, path: string): RecordedRequest[] {
    return this.requests.filter((request) => request.method === method && request.path === path);
  }

  readonly fetch: typeof globalThis.fetch = async (input, init) => {
    const url = new URL(input instanceof Request ? input.url : String(input));
    const request: RecordedRequest = {
      method: init?.method ?? 'GET',
      path: url.pathname,
      query: url.searchParams,
      headers: new Headers(init?.headers),
    };
    this.requests.push(request);
    this.onRequest?.(request);

    if (request.headers.get('Authorization') !== `Bearer ${this.token}`) {
      return json(401, { error: 'Unauthorized' });
    }

    const id = /^\/v1\/messages\/([^/]+)$/.exec(url.pathname)?.[1];
    if (request.method === 'GET' && url.pathname === '/v1/messages') {
      return this.#list(url.searchParams, init?.signal ?? undefined);
    }
    if (request.method === 'GET' && id !== undefined) {
      const found = this.#visible().find((stored) => stored.raw.id === decodeURIComponent(id));

      return found === undefined ? json(404, { error: 'mail inconnu' }) : json(200, found.raw);
    }
    if (request.method === 'DELETE' && id !== undefined) {
      const found = this.#visible().find((stored) => stored.raw.id === decodeURIComponent(id));
      if (found === undefined) {
        return json(404, { error: 'mail inconnu' });
      }
      this.#messages = this.#messages.filter((stored) => stored !== found);

      return json(200, { id: found.raw.id, deleted: true });
    }
    if (request.method === 'GET' && url.pathname === '/v1/addresses') {
      const addresses = this.addresses.map((address) => ({
        address,
        messages: this.#messages.filter((stored) => recipients(stored.raw).includes(address)).length,
      }));

      return json(200, { count: addresses.length, addresses });
    }
    if (request.method === 'DELETE' && url.pathname === '/v1/addresses') {
      const address = (url.searchParams.get('address') ?? '').trim().toLowerCase();
      if (address === '') {
        return json(400, { error: 'paramètre « address » obligatoire' });
      }
      if (!this.addresses.includes(address)) {
        return json(403, { error: `l'adresse « ${address} » n'est pas associée à ce jeton` });
      }
      const before = this.#messages.length;
      this.#messages = this.#messages.filter((stored) => !recipients(stored.raw).includes(address));

      return json(200, { address, deleted: before - this.#messages.length });
    }

    return json(404, { message: '404 Not Found' });
  };

  async #list(query: URLSearchParams, signal: AbortSignal | undefined): Promise<Response> {
    const limit = Number(query.get('limit') ?? 5);
    if (!Number.isInteger(limit) || limit < 1 || limit > 25) {
      return json(400, { error: '« limit » doit être compris entre 1 et 25' });
    }
    if (query.has('offset') && query.has('page')) {
      return json(400, { error: 'utilisez « offset » ou « page », pas les deux' });
    }
    const offset = query.has('page') ? (Number(query.get('page')) - 1) * limit : Number(query.get('offset') ?? 0);

    let addresses = this.addresses;
    if (query.has('address')) {
      const address = (query.get('address') ?? '').trim().toLowerCase();
      if (!this.addresses.includes(address)) {
        return json(403, { error: `l'adresse « ${address} » n'est pas associée à ce jeton` });
      }
      addresses = [address];
    }

    let since: number | null = null;
    if (query.has('since')) {
      const value = query.get('since') ?? '';
      if (!/(Z|[+-]\d{2}:?\d{2})$/i.test(value) || Number.isNaN(Date.parse(value))) {
        return json(400, { error: '« since » doit être une date ISO 8601 avec fuseau, par exemple 2026-11-09T14:03:00Z' });
      }
      since = Date.parse(value);
    }

    const wait = query.has('wait') ? Number(query.get('wait')) : 0;
    if (query.has('wait') && (!Number.isInteger(wait) || wait < 1 || wait > 60)) {
      return json(400, { error: '« wait » doit être un nombre de secondes entre 1 et 60' });
    }

    const completeOnly = wait > 0;
    const matches = (): StoredMessage[] =>
      this.#messages.filter((stored) =>
        recipients(stored.raw).some((address) => addresses.includes(address))
        && (since === null || Date.parse(stored.raw.created_at) > since)
        && (!completeOnly || stored.raw.complete));

    let waitedMs: number | null = null;
    if (wait > 0) {
      const started = Date.now();
      if (addresses.length > 0) {
        await this.#waitFor(() => matches().length > 0, wait * this.waitScale, signal);
      }
      waitedMs = Date.now() - started;
    }

    const all = matches().sort((a, b) =>
      Date.parse(b.raw.created_at) - Date.parse(a.raw.created_at) || b.seq - a.seq);
    const messages = all.slice(offset, offset + limit).map((stored) => summary(stored.raw));

    return json(200, {
      total: all.length,
      count: messages.length,
      limit,
      offset,
      page: Math.floor(offset / limit) + 1,
      ...(waitedMs === null ? {} : { waited_ms: waitedMs }),
      messages,
    });
  }

  #visible(): StoredMessage[] {
    return this.#messages.filter((stored) => recipients(stored.raw).some((address) => this.addresses.includes(address)));
  }

  #notify(): void {
    for (const listener of this.#listeners) {
      listener();
    }
  }

  /** Attend que `condition` soit vraie (vérifiée à chaque changement), au plus `ms` millisecondes. */
  #waitFor(condition: () => boolean, ms: number, signal: AbortSignal | undefined): Promise<void> {
    return new Promise((resolve, reject) => {
      const done = (): void => {
        clearTimeout(timer);
        this.#listeners.delete(check);
        signal?.removeEventListener('abort', abort);
      };
      const check = (): void => {
        if (condition()) {
          done();
          resolve();
        }
      };
      const abort = (): void => {
        done();
        reject(signal?.reason);
      };
      const timer = setTimeout(() => {
        done();
        resolve();
      }, ms);
      this.#listeners.add(check);
      signal?.addEventListener('abort', abort, { once: true });
      check();
    });
  }
}

function recipients(raw: RawMessageSummary): string[] {
  return [...raw.to, ...raw.cc, ...raw.bcc].map((entry) => entry.address.toLowerCase());
}

/** Le résumé d'un mail : sans corps ni en-têtes, comme dans la liste de l'API. */
function summary(raw: RawMessage): RawMessageSummary {
  const { text: _text, html: _html, headers: _headers, ...rest } = raw;

  return rest;
}

export function json(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ...headers },
  });
}
