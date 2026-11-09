import { readFileSync } from 'node:fs';
import { inspect } from 'node:util';
import { describe, expect, it } from 'vitest';
import {
  AmnesicMailClient,
  AmnesicMailError,
  ApiError,
  AuthenticationError,
  BadRequestError,
  NotFoundError,
  PermissionDeniedError,
  VERSION,
} from '../src/index.js';
import { json } from './support/fake-api.js';
import { rawMessage, rawSummary } from './support/fixtures.js';
import { scriptedFetch, type Step } from './support/scripted-fetch.js';

const TOKEN = 'mpt_3f9c0a1b2c3d4e5f60718293a4b5c6d7e8f9a0b1c2d3e4f5';

function client(...steps: Step[]) {
  const mock = scriptedFetch(...steps);
  const mail = new AmnesicMailClient({ baseUrl: 'https://api.mondomaine.fr', token: TOKEN, fetch: mock.fetch, retryDelay: 1 });

  return { mail, calls: mock.calls };
}

const emptyList = { total: 0, count: 0, limit: 5, offset: 0, page: 1, messages: [] };

describe('construction', () => {
  it('normalise baseUrl et garde un éventuel chemin (base_path)', async () => {
    const mock = scriptedFetch(json(200, emptyList));
    const mail = new AmnesicMailClient({ baseUrl: 'https://mondomaine.fr/mailpit-api//', token: TOKEN, fetch: mock.fetch });

    expect(mail.baseUrl).toBe('https://mondomaine.fr/mailpit-api');
    await mail.listMessages();
    expect(mock.calls[0]?.url.href).toBe('https://mondomaine.fr/mailpit-api/v1/messages');
  });

  it('accepte une URL', () => {
    expect(new AmnesicMailClient({ baseUrl: new URL('https://api.mondomaine.fr/'), token: TOKEN }).baseUrl)
      .toBe('https://api.mondomaine.fr');
  });

  it('refuse http:// sauf vers localhost', () => {
    expect(() => new AmnesicMailClient({ baseUrl: 'http://api.mondomaine.fr', token: TOKEN })).toThrow(/https:\/\//);
    for (const baseUrl of ['http://localhost:8080', 'http://127.0.0.1:8080', 'http://[::1]:8080', 'http://api.localhost']) {
      expect(new AmnesicMailClient({ baseUrl, token: TOKEN }).baseUrl).toBe(baseUrl);
    }
  });

  it('refuse une baseUrl invalide, avec identifiants ou paramètres', () => {
    expect(() => new AmnesicMailClient({ baseUrl: 'api.mondomaine.fr', token: TOKEN })).toThrow(TypeError);
    expect(() => new AmnesicMailClient({ baseUrl: 'https://admin:secret@api.mondomaine.fr', token: TOKEN })).toThrow(/identifiants/);
    expect(() => new AmnesicMailClient({ baseUrl: 'https://api.mondomaine.fr/?token=x', token: TOKEN })).toThrow(/paramètres/);
  });

  it('exige un jeton, sans espace', () => {
    expect(() => new AmnesicMailClient({ baseUrl: 'https://api.mondomaine.fr', token: '' })).toThrow(/token manquant/);
    expect(() => new AmnesicMailClient({ baseUrl: 'https://api.mondomaine.fr', token: undefined as unknown as string }))
      .toThrow(/token manquant/);
    expect(() => new AmnesicMailClient({ baseUrl: 'https://api.mondomaine.fr', token: `Bearer ${TOKEN}` })).toThrow(/espace/);
  });

  it('retire les espaces autour du jeton (fichier .env)', async () => {
    const mock = scriptedFetch(json(200, emptyList));
    await new AmnesicMailClient({ baseUrl: 'https://api.mondomaine.fr', token: ` ${TOKEN}\n`, fetch: mock.fetch }).listMessages();

    expect(mock.calls[0]?.headers.get('Authorization')).toBe(`Bearer ${TOKEN}`);
  });

  it('valide les options numériques', () => {
    expect(() => new AmnesicMailClient({ baseUrl: 'https://a.fr', token: TOKEN, timeout: 0 })).toThrow(RangeError);
    expect(() => new AmnesicMailClient({ baseUrl: 'https://a.fr', token: TOKEN, maxRetries: 1.5 })).toThrow(RangeError);
    expect(() => new AmnesicMailClient({ baseUrl: 'https://a.fr', token: TOKEN, retryDelay: -1 })).toThrow(RangeError);
  });

  it("n'affiche jamais le jeton", () => {
    const mail = new AmnesicMailClient({ baseUrl: 'https://api.mondomaine.fr', token: TOKEN });

    expect(inspect(mail, { depth: 10, showHidden: true })).not.toContain(TOKEN);
    expect(JSON.stringify(mail)).not.toContain(TOKEN);
  });

  it('lit globalThis.fetch à chaque requête (pour les outils qui le remplacent après coup)', async () => {
    const mail = new AmnesicMailClient({ baseUrl: 'https://api.mondomaine.fr', token: TOKEN });
    const original = globalThis.fetch;
    const mock = scriptedFetch(json(200, emptyList));
    globalThis.fetch = mock.fetch;
    try {
      await mail.listMessages();
    } finally {
      globalThis.fetch = original;
    }

    expect(mock.calls).toHaveLength(1);
  });
});

describe('listMessages', () => {
  it('envoie le jeton dans l’en-tête, jamais dans l’URL', async () => {
    const { mail, calls } = client(json(200, emptyList));
    await mail.listMessages();

    const call = calls[0]!;
    expect(call.init.method).toBe('GET');
    expect(call.headers.get('Authorization')).toBe(`Bearer ${TOKEN}`);
    expect(call.headers.get('Accept')).toBe('application/json');
    expect(call.headers.get('User-Agent')).toBe(`amnesic-mail-sdk/${VERSION}`);
    expect(call.url.href).not.toContain(TOKEN);
    expect(call.init.redirect).toBe('manual');
  });

  it('transmet les filtres, et une Date en ISO 8601 UTC', async () => {
    const { mail, calls } = client(json(200, emptyList));
    await mail.listMessages({
      address: 'essai@test.mondomaine.fr',
      limit: 25,
      page: 2,
      since: new Date('2026-11-09T15:03:00.250+01:00'),
    });

    expect(Object.fromEntries(calls[0]!.url.searchParams)).toEqual({
      address: 'essai@test.mondomaine.fr',
      limit: '25',
      page: '2',
      since: '2026-11-09T14:03:00.250Z',
    });
  });

  it('encode le « + » d’un fuseau donné en texte', async () => {
    const { mail, calls } = client(json(200, emptyList));
    await mail.listMessages({ since: '2026-11-09T15:03:00+01:00', offset: 10 });

    expect(calls[0]!.url.search).toBe('?offset=10&since=2026-11-09T15%3A03%3A00%2B01%3A00');
  });

  it('refuse une Date invalide', async () => {
    const { mail } = client();

    await expect(mail.listMessages({ since: new Date('nope') })).rejects.toThrow(/since : date invalide/);
  });

  it('convertit la réponse : camelCase et dates', async () => {
    const { mail } = client(json(200, { total: 12, count: 1, limit: 1, offset: 0, page: 1, messages: [rawSummary()] }));
    const list = await mail.listMessages({ limit: 1 });

    expect(list).toEqual({
      total: 12,
      count: 1,
      limit: 1,
      offset: 0,
      page: 1,
      waitedMs: null,
      messages: [
        {
          id: '2qGgGUqCw5N3mAOHzyFGoJ',
          messageId: '2CJGY3JTeKc0YxSLai06kR@mailpit',
          from: { name: 'Alice', address: 'alice@exemple.fr' },
          to: [{ name: '', address: 'essai@test.mondomaine.fr' }],
          cc: [],
          bcc: [],
          replyTo: [],
          subject: 'Votre code',
          snippet: 'Votre code de confirmation : 482913',
          returnPath: 'alice@exemple.fr',
          tags: [],
          size: 1053210,
          attachments: 1,
          username: null,
          label: null,
          date: new Date('2026-11-09T08:31:20.000Z'),
          createdAt: new Date('2026-11-09T08:31:20.474Z'),
          receivedAt: new Date('2026-11-09T08:31:21.205Z'),
          complete: true,
          truncated: false,
          parts: [
            {
              partId: '2',
              kind: 'attachment',
              fileName: 'devis.pdf',
              contentType: 'application/pdf',
              contentId: '',
              size: 1048576,
              status: 'stored',
            },
          ],
        },
      ],
    });
    // La milliseconde est conservée : createdAt peut servir de since.
    expect(list.messages[0]!.createdAt.toISOString()).toBe('2026-11-09T08:31:20.474Z');
  });

  it('garde un expéditeur et une date absents à null', async () => {
    const { mail } = client(json(200, { ...emptyList, total: 1, count: 1, messages: [rawSummary({ from: null, date: null })] }));
    const [message] = (await mail.listMessages()).messages;

    expect(message?.from).toBeNull();
    expect(message?.date).toBeNull();
  });

  it('expose waited_ms avec wait', async () => {
    const { mail, calls } = client(json(200, { ...emptyList, waited_ms: 1534 }));
    const list = await mail.listMessages({ wait: 5 });

    expect(calls[0]!.url.searchParams.get('wait')).toBe('5');
    expect(list.waitedMs).toBe(1534);
  });
});

describe('getMessage', () => {
  it('renvoie le mail complet', async () => {
    const { mail, calls } = client(json(200, rawMessage()));
    const message = await mail.getMessage('2qGgGUqCw5N3mAOHzyFGoJ');

    expect(calls[0]!.url.pathname).toBe('/v1/messages/2qGgGUqCw5N3mAOHzyFGoJ');
    expect(message.subject).toBe('Votre code');
    expect(message.text).toBe('Votre code de confirmation : 482913\n');
    expect(message.html).toBe('<p>Votre code de confirmation : <b>482913</b></p>');
    expect(message.headers).toEqual({ Subject: ['Votre code'], 'Content-Type': ['multipart/mixed; boundary=xyz'] });
    expect(message.createdAt).toEqual(new Date('2026-11-09T08:31:20.474Z'));
  });

  it("encode l'ID dans l'URL et refuse un ID vide", async () => {
    const { mail, calls } = client(json(404, { error: 'mail inconnu' }));

    await expect(mail.getMessage('../addresses')).rejects.toThrow(NotFoundError);
    expect(calls[0]!.url.pathname).toBe('/v1/messages/..%2Faddresses');
    await expect(mail.getMessage('')).rejects.toThrow(TypeError);
  });

  it('404 : NotFoundError avec le détail de la réponse', async () => {
    const { mail } = client(json(404, { error: 'mail inconnu' }));
    const error = await mail.getMessage('inconnu').catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(NotFoundError);
    expect(error).toBeInstanceOf(ApiError);
    expect(error).toBeInstanceOf(AmnesicMailError);
    expect(error).toMatchObject({
      name: 'NotFoundError',
      status: 404,
      method: 'GET',
      path: '/v1/messages/inconnu',
      body: { error: 'mail inconnu' },
      apiMessage: 'mail inconnu',
      attempts: 1,
      message: 'GET /v1/messages/inconnu : HTTP 404 – mail inconnu',
    });
  });
});

describe('erreurs de l’API', () => {
  it('401 : AuthenticationError, sans le jeton dans le message', async () => {
    const { mail } = client(json(401, { error: 'Unauthorized' }, { 'WWW-Authenticate': 'Bearer' }));
    const error = await mail.listMessages().catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(AuthenticationError);
    expect((error as Error).message).toBe('GET /v1/messages : HTTP 401 – jeton absent, inconnu ou supprimé');
    expect((error as Error).message).not.toContain(TOKEN);
  });

  it("403 : PermissionDeniedError avec le message de l'API", async () => {
    const message = "l'adresse « autre@test.mondomaine.fr » n'est pas associée à ce jeton";
    const { mail } = client(json(403, { error: message }));
    const error = await mail.listMessages({ address: 'autre@test.mondomaine.fr' }).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(PermissionDeniedError);
    expect((error as ApiError).apiMessage).toBe(message);
    expect((error as Error).message).toContain(message);
  });

  it('403 « Forbidden » (jeton du relais hors des IP autorisées) : explication', async () => {
    const { mail } = client(json(403, { error: 'Forbidden' }));

    await expect(mail.listMessages()).rejects.toThrow(/jeton du relais/);
  });

  it('400 : BadRequestError', async () => {
    const { mail } = client(json(400, { error: '« limit » doit être compris entre 1 et 25' }));

    await expect(mail.listMessages({ limit: 100 })).rejects.toThrow(BadRequestError);
  });

  it('erreur Slim sans « error » : utilise « message »', async () => {
    const { mail } = client(json(405, { message: '405 Method Not Allowed' }));
    const error = await mail.listAddresses().catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).apiMessage).toBe('405 Method Not Allowed');
  });

  it("page d'erreur HTML d'un proxy : corps en texte", async () => {
    const { mail } = client(new Response('<html>Bad gateway</html>', { status: 418 }));
    const error = await mail.listMessages().catch((caught: unknown) => caught);

    expect(error).toMatchObject({ status: 418, body: '<html>Bad gateway</html>', apiMessage: null });
  });

  it('redirection non suivie : vérifier baseUrl', async () => {
    const { mail } = client(new Response(null, { status: 301, headers: { Location: 'https://ailleurs.fr/' } }));

    await expect(mail.listMessages()).rejects.toThrow(/HTTP 301 – redirection non suivie : vérifiez baseUrl/);
  });

  it('réponse 200 qui n’est pas du JSON : AmnesicMailError', async () => {
    const { mail } = client(new Response('<html>Hébergement suspendu</html>', { status: 200 }));
    const error = await mail.listMessages().catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(AmnesicMailError);
    expect(error).not.toBeInstanceOf(ApiError);
    expect((error as Error).message).toMatch(/pas du JSON/);
  });
});

describe('suppressions et adresses', () => {
  it('deleteMessage', async () => {
    const { mail, calls } = client(json(200, { id: 'abc', deleted: true }));

    expect(await mail.deleteMessage('abc')).toEqual({ id: 'abc', deleted: true });
    expect(calls[0]!.init.method).toBe('DELETE');
    expect(calls[0]!.url.pathname).toBe('/v1/messages/abc');
  });

  it('deleteMessage : 404 du premier coup = NotFoundError', async () => {
    const { mail } = client(json(404, { error: 'mail inconnu' }));

    await expect(mail.deleteMessage('abc')).rejects.toThrow(NotFoundError);
  });

  it('listAddresses', async () => {
    const { mail, calls } = client(json(200, {
      count: 2,
      addresses: [{ address: 'alice@test.mondomaine.fr', messages: 3 }, { address: 'essai@test.mondomaine.fr', messages: 0 }],
    }));

    expect(await mail.listAddresses()).toEqual([
      { address: 'alice@test.mondomaine.fr', messageCount: 3 },
      { address: 'essai@test.mondomaine.fr', messageCount: 0 },
    ]);
    expect(calls[0]!.url.pathname).toBe('/v1/addresses');
  });

  it('deleteAddressMessages', async () => {
    const { mail, calls } = client(json(200, { address: 'essai@test.mondomaine.fr', deleted: 3 }));

    expect(await mail.deleteAddressMessages('essai@test.mondomaine.fr')).toEqual({ address: 'essai@test.mondomaine.fr', deletedCount: 3 });
    expect(calls[0]!.init.method).toBe('DELETE');
    expect(calls[0]!.url.pathname).toBe('/v1/addresses');
    expect(calls[0]!.url.searchParams.get('address')).toBe('essai@test.mondomaine.fr');
  });

  it('deleteAddressMessages : adresse obligatoire', async () => {
    const { mail, calls } = client();

    await expect(mail.deleteAddressMessages(' ')).rejects.toThrow(TypeError);
    expect(calls).toHaveLength(0);
  });
});

describe('paquet', () => {
  it('VERSION est celle de package.json', () => {
    const pkg = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8')) as { version: string };

    expect(VERSION).toBe(pkg.version);
  });
});
