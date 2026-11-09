import { AmnesicMailError, ApiError, ConnectionError, RequestTimeoutError } from './errors.js';
import { VERSION } from './version.js';

export type QueryValue = string | number | undefined;

export interface HttpConfig {
  /** URL de base normalisée, sans « / » final. */
  baseUrl: string;
  token: string;
  timeout: number;
  maxRetries: number;
  retryDelay: number;
  fetch: typeof globalThis.fetch | undefined;
}

export interface HttpRequest {
  method: 'GET' | 'DELETE';
  path: string;
  query?: Record<string, QueryValue>;
  signal?: AbortSignal | undefined;
  /** Délai accordé en plus de `timeout` (attente côté API), en millisecondes. */
  extraTimeout?: number;
}

export interface HttpResponse<T> {
  data: T;
  /** Nombre d'essais, relances comprises. */
  attempts: number;
}

/**
 * Codes qui valent une relance : l'API (ou un proxy devant elle) n'a pas pu répondre, réessayer
 * peut réussir. Jamais les autres 4xx : la même requête recevrait la même réponse.
 */
const RETRYABLE_STATUSES = new Set([408, 429, 500, 502, 503, 504]);
/** Délai maximal entre deux essais, backoff exponentiel compris. */
const MAX_BACKOFF = 8_000;
/** Un `Retry-After` plus long est ramené à cette durée. */
const MAX_RETRY_AFTER = 30_000;

const USER_AGENT = `amnesic-mail-sdk/${VERSION}`;

interface Attempt {
  response: Response;
  body: unknown;
}

/**
 * Requêtes vers l'API : jeton dans l'en-tête `Authorization` (jamais dans l'URL), délai maximal,
 * relances avec backoff exponentiel, et conversion des réponses d'erreur en `ApiError`.
 */
export class HttpClient {
  readonly #config: HttpConfig;

  constructor(config: HttpConfig) {
    this.#config = config;
  }

  async request<T>(request: HttpRequest): Promise<HttpResponse<T>> {
    const path = HttpClient.#pathWithQuery(request);
    const url = this.#config.baseUrl + path;
    const timeout = this.#config.timeout + (request.extraTimeout ?? 0);
    const label = `${request.method} ${path}`;

    for (let attempt = 1; ; attempt++) {
      request.signal?.throwIfAborted();

      let result: Attempt;
      try {
        result = await this.#attempt(request, url, timeout, label);
      } catch (error) {
        if (request.signal?.aborted) {
          throw request.signal.reason;
        }
        // Délai dépassé : pas de relance, l'attente aurait déjà consommé le temps prévu.
        if (error instanceof AmnesicMailError) {
          throw error;
        }
        if (attempt > this.#config.maxRetries) {
          throw new ConnectionError(`${label} : API injoignable (${describe(error)})`, { cause: error });
        }
        await sleep(this.#backoff(attempt), request.signal);
        continue;
      }

      const { response, body } = result;
      if (response.ok) {
        if (typeof body !== 'object' || body === null) {
          throw new AmnesicMailError(`${label} : réponse inattendue de l'API (HTTP ${response.status}, pas du JSON)`);
        }

        return { data: body as T, attempts: attempt };
      }

      if (RETRYABLE_STATUSES.has(response.status) && attempt <= this.#config.maxRetries) {
        await sleep(this.#retryDelay(response, attempt), request.signal);
        continue;
      }

      throw ApiError.from({ status: response.status, method: request.method, path, body, attempts: attempt });
    }
  }

  async #attempt(request: HttpRequest, url: string, timeout: number, label: string): Promise<Attempt> {
    const timeoutSignal = AbortSignal.timeout(timeout);
    const signal = request.signal === undefined ? timeoutSignal : AbortSignal.any([request.signal, timeoutSignal]);
    const fetch = this.#config.fetch ?? globalThis.fetch;

    try {
      // Le délai couvre aussi la lecture du corps.
      const response = await fetch(url, {
        method: request.method,
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${this.#config.token}`,
          'User-Agent': USER_AGENT,
        },
        // Une redirection n'est pas suivie : elle signale une baseUrl à corriger (ApiError 3xx).
        redirect: 'manual',
        signal,
      });
      const body = parseBody(await response.text());

      return { response, body };
    } catch (error) {
      if (timeoutSignal.aborted && !(request.signal?.aborted ?? false)) {
        throw new RequestTimeoutError(`${label} : pas de réponse de l'API en ${timeout} ms`, timeout);
      }
      throw error;
    }
  }

  /** Backoff exponentiel, avec une part d'aléatoire pour ne pas relancer tous en même temps. */
  #backoff(attempt: number): number {
    const delay = Math.min(MAX_BACKOFF, this.#config.retryDelay * 2 ** (attempt - 1));

    return delay / 2 + Math.random() * (delay / 2);
  }

  #retryDelay(response: Response, attempt: number): number {
    const retryAfter = parseRetryAfter(response.headers.get('Retry-After'));

    return retryAfter === null ? this.#backoff(attempt) : Math.min(MAX_RETRY_AFTER, retryAfter);
  }

  static #pathWithQuery(request: HttpRequest): string {
    const params = new URLSearchParams();
    for (const [name, value] of Object.entries(request.query ?? {})) {
      if (value !== undefined) {
        params.set(name, String(value));
      }
    }
    const query = params.toString();

    return query === '' ? request.path : `${request.path}?${query}`;
  }
}

/** JSON décodé, texte brut s'il n'est pas du JSON (page d'erreur d'un proxy…), `null` si vide. */
function parseBody(text: string): unknown {
  if (text === '') {
    return null;
  }
  try {
    return JSON.parse(text) as unknown;
  } catch {
    return text;
  }
}

/** `Retry-After` en millisecondes : nombre de secondes ou date HTTP. `null` si absent ou invalide. */
export function parseRetryAfter(value: string | null): number | null {
  if (value === null || value.trim() === '') {
    return null;
  }
  if (/^\d+$/.test(value.trim())) {
    return Number(value.trim()) * 1000;
  }
  const date = Date.parse(value);

  return Number.isNaN(date) ? null : Math.max(0, date - Date.now());
}

/** Cause lisible d'une erreur de `fetch` (Node la cache souvent dans `cause`). */
function describe(error: unknown): string {
  if (!(error instanceof Error)) {
    return String(error);
  }
  const cause = error.cause;
  if (cause instanceof Error) {
    const code = (cause as Error & { code?: unknown }).code;

    return typeof code === 'string' ? `${code} : ${cause.message}` : cause.message;
  }

  return error.message;
}

/** Pause annulable : rejette avec la raison du signal, comme `fetch`. */
export function sleep(ms: number, signal?: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) {
      reject(signal.reason);

      return;
    }
    const onAbort = (): void => {
      clearTimeout(timer);
      reject(signal?.reason);
    };
    const timer = setTimeout(() => {
      signal?.removeEventListener('abort', onAbort);
      resolve();
    }, ms);
    signal?.addEventListener('abort', onAbort, { once: true });
  });
}
