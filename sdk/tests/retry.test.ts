import { describe, expect, it } from 'vitest';
import {
  AmnesicMailClient,
  ApiError,
  ConnectionError,
  NotFoundError,
  RequestTimeoutError,
  type AmnesicMailClientOptions,
} from '../src/index.js';
import { parseRetryAfter } from '../src/http.js';
import { json } from './support/fake-api.js';
import { networkError, scriptedFetch, type Step } from './support/scripted-fetch.js';

const emptyList = { total: 0, count: 0, limit: 5, offset: 0, page: 1, messages: [] };

function client(options: Partial<AmnesicMailClientOptions>, ...steps: Step[]) {
  const mock = scriptedFetch(...steps);
  const mail = new AmnesicMailClient({ baseUrl: 'https://api.mondomaine.fr', token: 'mpt_x', fetch: mock.fetch, retryDelay: 1, ...options });

  return { mail, calls: mock.calls };
}

/** Réponse qui n'arrive jamais, sauf annulation (le fetch rejette alors avec la raison du signal). */
const hang: Step = (call) =>
  new Promise((_resolve, reject) => {
    const signal = call.init.signal!;
    signal.addEventListener('abort', () => reject(signal.reason), { once: true });
  });

describe('relances', () => {
  it('relance après une panne réseau', async () => {
    const { mail, calls } = client({}, networkError(), json(200, emptyList));

    await expect(mail.listMessages()).resolves.toMatchObject({ total: 0 });
    expect(calls).toHaveLength(2);
  });

  it('relance après 429, 500, 502, 503 et 504', async () => {
    const { mail, calls } = client(
      { maxRetries: 5 },
      json(429, { error: 'trop de requêtes' }),
      json(500, { message: '500 Internal Server Error' }),
      json(502, {}),
      json(503, {}),
      json(504, {}),
      json(200, emptyList),
    );

    await expect(mail.listMessages()).resolves.toMatchObject({ total: 0 });
    expect(calls).toHaveLength(6);
  });

  it('abandonne après maxRetries relances : ConnectionError avec la cause', async () => {
    const { mail, calls } = client({ maxRetries: 2 }, networkError(), networkError(), networkError('ENOTFOUND'));
    const error = await mail.listMessages().catch((caught: unknown) => caught);

    expect(calls).toHaveLength(3);
    expect(error).toBeInstanceOf(ConnectionError);
    expect((error as Error).message).toBe('GET /v1/messages : API injoignable (ENOTFOUND : connect ENOTFOUND 203.0.113.10:443)');
    expect((error as Error).cause).toBeInstanceOf(TypeError);
  });

  it('abandonne après maxRetries relances : la dernière erreur HTTP', async () => {
    const { mail, calls } = client({ maxRetries: 1 }, json(503, {}), json(503, { error: 'maintenance' }));
    const error = await mail.listMessages().catch((caught: unknown) => caught);

    expect(calls).toHaveLength(2);
    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({ status: 503, attempts: 2, apiMessage: 'maintenance' });
  });

  it('maxRetries: 0 désactive les relances', async () => {
    const { mail, calls } = client({ maxRetries: 0 }, networkError());

    await expect(mail.listMessages()).rejects.toThrow(ConnectionError);
    expect(calls).toHaveLength(1);
  });

  it('jamais sur les autres 4xx', async () => {
    for (const status of [400, 401, 403, 404, 405, 413, 422]) {
      const { mail, calls } = client({}, json(status, { error: 'non' }));

      await expect(mail.listMessages()).rejects.toThrow(ApiError);
      expect(calls).toHaveLength(1);
    }
  });

  it('respecte Retry-After', async () => {
    const { mail, calls } = client({ retryDelay: 5_000 }, json(503, {}, { 'Retry-After': '0' }), json(200, emptyList));
    const started = Date.now();

    await mail.listMessages();
    expect(calls).toHaveLength(2);
    expect(Date.now() - started).toBeLessThan(1_000);
  });

  it('deleteMessage : un 404 après une réponse perdue veut dire « déjà supprimé »', async () => {
    const { mail, calls } = client({}, networkError('ECONNRESET'), json(404, { error: 'mail inconnu' }));

    await expect(mail.deleteMessage('abc')).resolves.toEqual({ id: 'abc', deleted: true });
    expect(calls).toHaveLength(2);
  });

  it('getMessage : un 404 après relance reste une erreur', async () => {
    const { mail } = client({}, networkError(), json(404, { error: 'mail inconnu' }));

    await expect(mail.getMessage('abc')).rejects.toThrow(NotFoundError);
  });
});

describe('délais et annulation', () => {
  it('RequestTimeoutError, sans relance', async () => {
    const { mail, calls } = client({ timeout: 30 }, hang, hang);
    const error = await mail.listMessages().catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(RequestTimeoutError);
    expect(error).toMatchObject({ timeout: 30, message: 'GET /v1/messages : pas de réponse de l\'API en 30 ms' });
    expect(calls).toHaveLength(1);
  });

  it('une requête avec wait a droit à son attente en plus du délai', async () => {
    const slow: Step = () => new Promise((resolve) => setTimeout(() => resolve(json(200, { ...emptyList, waited_ms: 150 })), 150));
    const { mail, calls } = client({ timeout: 30 }, slow);

    await expect(mail.listMessages({ wait: 1 })).resolves.toMatchObject({ waitedMs: 150 });
    expect(calls).toHaveLength(1);
  });

  it('annulation par le signal : rejette avec sa raison, sans relance', async () => {
    const { mail, calls } = client({}, hang, hang);
    const controller = new AbortController();
    const pending = mail.listMessages({ signal: controller.signal });
    setTimeout(() => controller.abort(new Error('test annulé')), 10);

    await expect(pending).rejects.toThrow('test annulé');
    expect(calls).toHaveLength(1);
  });

  it('signal déjà annulé : aucune requête', async () => {
    const { mail, calls } = client({});

    await expect(mail.listMessages({ signal: AbortSignal.abort() })).rejects.toThrow(/abort/i);
    expect(calls).toHaveLength(0);
  });

  it("annulation pendant la pause avant une relance", async () => {
    const { mail, calls } = client({ retryDelay: 10_000 }, json(503, {}));
    const controller = new AbortController();
    const pending = mail.listMessages({ signal: controller.signal });
    setTimeout(() => controller.abort(new Error('stop')), 20);

    await expect(pending).rejects.toThrow('stop');
    expect(calls).toHaveLength(1);
  });
});

describe('parseRetryAfter', () => {
  it('secondes ou date HTTP, en millisecondes', () => {
    expect(parseRetryAfter('3')).toBe(3_000);
    expect(parseRetryAfter(' 0 ')).toBe(0);
    expect(parseRetryAfter(null)).toBeNull();
    expect(parseRetryAfter('bientôt')).toBeNull();
    expect(parseRetryAfter(new Date(Date.now() - 60_000).toUTCString())).toBe(0);

    const future = parseRetryAfter(new Date(Date.now() + 10_000).toUTCString());
    expect(future).toBeGreaterThan(8_000);
    expect(future).toBeLessThanOrEqual(10_000);
  });
});
