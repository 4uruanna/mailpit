import { describe, expect, it } from 'vitest';
import { AmnesicMailClient, WaitTimeoutError } from '../src/index.js';
import { FakeApi } from './support/fake-api.js';

const ESSAI = 'essai@test.mondomaine.fr';
const ALICE = 'alice@test.mondomaine.fr';
const to = (address: string) => [{ name: '', address }];

function setup(options: { addresses?: string[]; waitScale?: number } = {}) {
  const api = new FakeApi(options);
  const mail = new AmnesicMailClient({ baseUrl: 'https://api.mondomaine.fr', token: api.token, fetch: api.fetch, retryDelay: 1 });

  return { api, mail };
}

describe('checkpoint', () => {
  it('date de réception du mail le plus récent, ou null', async () => {
    const { api, mail } = setup();
    expect(await mail.checkpoint({ address: ESSAI })).toBeNull();

    api.add({ to: to(ESSAI) });
    const latest = api.add({ to: to(ESSAI) });
    api.add({ to: to(ALICE) });

    expect(await mail.checkpoint({ address: ESSAI })).toEqual(new Date(latest.created_at));
    expect(api.requests.at(-1)?.query.get('limit')).toBe('1');
  });

  it('compte aussi un mail incomplet : il est arrivé avant le repère', async () => {
    const { api, mail } = setup();
    const incomplete = api.add({ to: to(ESSAI), complete: false });

    expect(await mail.checkpoint()).toEqual(new Date(incomplete.created_at));
  });
});

describe('waitForMessage', () => {
  it('renvoie le mail complet qui arrive après since', async () => {
    const { api, mail } = setup();
    api.add({ to: to(ESSAI), subject: 'Ancien' });
    const since = await mail.checkpoint({ address: ESSAI });
    api.add({ to: to(ESSAI), subject: 'Bienvenue', text: 'Votre code : 123456' }, { delay: 50 });

    const message = await mail.waitForMessage({ address: ESSAI, since });

    expect(message.subject).toBe('Bienvenue');
    expect(message.text).toBe('Votre code : 123456');
    // La 1re requête est celle de checkpoint().
    const [, first] = api.requestsTo('GET', '/v1/messages');
    expect(first?.query.get('wait')).toBe('60');
    expect(first?.query.get('since')).toBe(since?.toISOString());
    expect(first?.query.get('address')).toBe(ESSAI);
  });

  it('sans since : un mail déjà présent compte', async () => {
    const { api, mail } = setup();
    api.add({ to: to(ESSAI), subject: 'Déjà là' });

    expect((await mail.waitForMessage({ address: ESSAI })).subject).toBe('Déjà là');
  });

  it('plusieurs mails : le plus ancien après since, puis le suivant', async () => {
    const { api, mail } = setup();
    const since = await mail.checkpoint();
    api.add({ to: to(ESSAI), subject: 'Premier' });
    api.add({ to: to(ESSAI), subject: 'Deuxième' });

    const first = await mail.waitForMessage({ since });
    const second = await mail.waitForMessage({ since: first.createdAt });

    expect([first.subject, second.subject]).toEqual(['Premier', 'Deuxième']);
  });

  it('ignore un mail incomplet tant que ses pièces jointes manquent', async () => {
    const { api, mail } = setup();
    const message = api.add({ to: to(ESSAI), complete: false });
    api.completeLater(message.id, 80);
    const started = Date.now();

    const received = await mail.waitForMessage({ address: ESSAI });

    expect(received.id).toBe(message.id);
    expect(received.complete).toBe(true);
    expect(Date.now() - started).toBeGreaterThanOrEqual(70);
  });

  it('filter : écarte les autres mails et attend le bon', async () => {
    const { api, mail } = setup();
    api.add({ to: to(ESSAI), subject: 'Bienvenue' });
    api.add({ to: to(ESSAI), subject: 'Votre code' }, { delay: 120 });

    const message = await mail.waitForMessage({
      address: ESSAI,
      filter: (summary) => summary.subject.includes('code'),
      pollInterval: 40,
    });

    expect(message.subject).toBe('Votre code');
    // L'API répond tout de suite (un mail complet existe déjà) : une requête par pollInterval au plus.
    expect(api.requestsTo('GET', '/v1/messages').length).toBeLessThanOrEqual(8);
  });

  it('filter asynchrone', async () => {
    const { api, mail } = setup();
    api.add({ to: to(ESSAI), subject: 'A' });
    api.add({ to: to(ESSAI), subject: 'B' });

    const message = await mail.waitForMessage({ filter: async (summary) => summary.subject === 'B' });

    expect(message.subject).toBe('B');
  });

  it('plus de 25 mails après since : lit les pages depuis la fin pour trouver le plus ancien', async () => {
    const { api, mail } = setup();
    const since = await mail.checkpoint();
    for (let i = 1; i <= 30; i++) {
      api.add({ to: to(ESSAI), subject: `Mail ${i}` });
    }

    expect((await mail.waitForMessage({ since })).subject).toBe('Mail 1');
    expect((await mail.waitForMessage({ since, filter: (m) => m.subject === 'Mail 7' })).subject).toBe('Mail 7');
    expect((await mail.waitForMessage({ since, filter: (m) => m.subject === 'Mail 29' })).subject).toBe('Mail 29');
  });

  it("un mail supprimé entre la liste et la lecture est sauté", async () => {
    const { api, mail } = setup();
    const doomed = api.add({ to: to(ESSAI), subject: 'Supprimé' });
    api.add({ to: to(ESSAI), subject: 'Gardé' });
    api.onRequest = (request) => {
      if (request.method === 'GET' && request.path === `/v1/messages/${doomed.id}`) {
        void mail.deleteMessage(doomed.id);
      }
    };

    expect((await mail.waitForMessage()).subject).toBe('Gardé');
  });

  it('WaitTimeoutError si rien n’arrive à temps', async () => {
    const { api, mail } = setup({ waitScale: 20 });
    api.add({ to: to(ESSAI), subject: 'Ancien' });
    const since = await mail.checkpoint();

    const error = await mail.waitForMessage({ address: ESSAI, since, timeout: 150 }).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(WaitTimeoutError);
    expect(error).toMatchObject({ timeout: 150 });
    expect((error as Error).message).toBe(`aucun mail complet pour ${ESSAI} après ${since?.toISOString()} en 150 ms`);
  });

  it('WaitTimeoutError indique les mails écartés par le filtre', async () => {
    const { api, mail } = setup();
    api.add({ to: to(ESSAI), subject: 'Bienvenue' });

    await expect(mail.waitForMessage({ filter: () => false, timeout: 100, pollInterval: 30 }))
      .rejects.toThrow('aucun mail complet en 100 ms (1 mail(s) écarté(s) par le filtre)');
  });

  it("timeout: 0 : une seule vérification, sans attente", async () => {
    const { api, mail } = setup();

    await expect(mail.waitForMessage({ timeout: 0 })).rejects.toThrow(WaitTimeoutError);
    const requests = api.requestsTo('GET', '/v1/messages');
    expect(requests).toHaveLength(1);
    expect(requests[0]?.query.has('wait')).toBe(false);
  });

  it('au-delà de 60 s : enchaîne des attentes de 60 s au plus', async () => {
    // Une « seconde » de wait dure 1 ms dans la fausse API : le mail arrive après 2 attentes complètes.
    const { api, mail } = setup({ waitScale: 1 });
    api.add({ to: to(ESSAI) }, { delay: 150 });

    await mail.waitForMessage({ timeout: 125_000, pollInterval: 0 });

    const waits = api.requestsTo('GET', '/v1/messages').map((request) => request.query.get('wait'));
    expect(waits.length).toBeGreaterThan(1);
    expect(new Set(waits)).toEqual(new Set(['60']));
  });

  it('wait suit le délai restant, arrondi à la seconde supérieure', async () => {
    const { api, mail } = setup({ waitScale: 1 });
    const controller = new AbortController();
    api.onRequest = () => controller.abort(new Error('stop'));

    await expect(mail.waitForMessage({ timeout: 2_500, signal: controller.signal })).rejects.toThrow('stop');
    expect(api.requestsTo('GET', '/v1/messages')[0]?.query.get('wait')).toBe('3');
  });

  it("jeton sans adresse : l'API répond sans attendre, le SDK temporise", async () => {
    const { api, mail } = setup({ addresses: [] });

    await expect(mail.waitForMessage({ timeout: 200, pollInterval: 60 })).rejects.toThrow(WaitTimeoutError);
    expect(api.requestsTo('GET', '/v1/messages').length).toBeLessThanOrEqual(5);
  });

  it('annulation par le signal', async () => {
    const { mail } = setup();
    const controller = new AbortController();
    const pending = mail.waitForMessage({ signal: controller.signal, timeout: 60_000 });
    setTimeout(() => controller.abort(new Error('test terminé')), 30);

    await expect(pending).rejects.toThrow('test terminé');
  });

  it('valide ses options', async () => {
    const { mail } = setup();

    await expect(mail.waitForMessage({ timeout: -1 })).rejects.toThrow(RangeError);
    await expect(mail.waitForMessage({ since: new Date('invalide') })).rejects.toThrow(TypeError);
  });
});

describe('iterateMessages', () => {
  it('parcourt toutes les pages, du plus récent au plus ancien', async () => {
    const { api, mail } = setup();
    for (let i = 1; i <= 60; i++) {
      api.add({ to: to(i % 2 === 0 ? ESSAI : ALICE), subject: `Mail ${i}` });
    }

    const subjects: string[] = [];
    for await (const summary of mail.iterateMessages()) {
      subjects.push(summary.subject);
    }

    expect(subjects).toHaveLength(60);
    expect(subjects[0]).toBe('Mail 60');
    expect(subjects.at(-1)).toBe('Mail 1');
    expect(api.requestsTo('GET', '/v1/messages').map((request) => request.query.get('offset'))).toEqual(['0', '25', '50']);
  });

  it('filtre par adresse et since, avec la taille de page voulue', async () => {
    const { api, mail } = setup();
    api.add({ to: to(ESSAI), subject: 'Avant' });
    const since = await mail.checkpoint();
    for (let i = 1; i <= 5; i++) {
      api.add({ to: to(ESSAI), subject: `Après ${i}` });
      api.add({ to: to(ALICE) });
    }

    const subjects: string[] = [];
    for await (const summary of mail.iterateMessages({ address: ESSAI, since, pageSize: 2 })) {
      subjects.push(summary.subject);
    }

    expect(subjects).toEqual(['Après 5', 'Après 4', 'Après 3', 'Après 2', 'Après 1']);
  });

  it("un mail arrivé pendant le parcours ne fait pas renvoyer un mail deux fois", async () => {
    const { api, mail } = setup();
    for (let i = 1; i <= 4; i++) {
      api.add({ to: to(ESSAI), subject: `Mail ${i}` });
    }
    // Arrive juste avant la 2e page : sans précaution, « Mail 3 » reviendrait en tête de cette page.
    api.onRequest = (request) => {
      if (request.query.get('offset') === '2') {
        api.add({ to: to(ESSAI), subject: 'Nouveau' });
      }
    };

    const subjects: string[] = [];
    for await (const summary of mail.iterateMessages({ pageSize: 2 })) {
      subjects.push(summary.subject);
    }

    expect(subjects).toEqual(['Mail 4', 'Mail 3', 'Mail 2', 'Mail 1']);
  });

  it('aucun mail : une seule requête', async () => {
    const { api, mail } = setup();
    const all = [];
    for await (const summary of mail.iterateMessages()) {
      all.push(summary);
    }

    expect(all).toEqual([]);
    expect(api.requestsTo('GET', '/v1/messages')).toHaveLength(1);
  });
});
