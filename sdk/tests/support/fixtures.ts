import type { RawMessage, RawMessageSummary } from '../../src/mappers.js';

/** Un résumé tel que le renvoie l'API (exemple de api/README.md, « Lister les mails »). */
export function rawSummary(overrides: Partial<RawMessageSummary> = {}): RawMessageSummary {
  return {
    id: '2qGgGUqCw5N3mAOHzyFGoJ',
    message_id: '2CJGY3JTeKc0YxSLai06kR@mailpit',
    from: { name: 'Alice', address: 'alice@exemple.fr' },
    to: [{ name: '', address: 'essai@test.mondomaine.fr' }],
    cc: [],
    bcc: [],
    reply_to: [],
    subject: 'Votre code',
    snippet: 'Votre code de confirmation : 482913',
    return_path: 'alice@exemple.fr',
    tags: [],
    size: 1053210,
    attachments: 1,
    username: null,
    label: null,
    date: '2026-11-09T08:31:20.000Z',
    created_at: '2026-11-09T08:31:20.474Z',
    received_at: '2026-11-09T08:31:21.205Z',
    complete: true,
    truncated: false,
    parts: [
      {
        part_id: '2',
        kind: 'attachment',
        file_name: 'devis.pdf',
        content_type: 'application/pdf',
        content_id: '',
        size: 1048576,
        status: 'stored',
      },
    ],
    ...overrides,
  };
}

/** Un mail complet tel que le renvoie l'API (exemple de api/README.md, « Lire un mail »). */
export function rawMessage(overrides: Partial<RawMessage> = {}): RawMessage {
  return {
    ...rawSummary(),
    text: 'Votre code de confirmation : 482913\n',
    html: '<p>Votre code de confirmation : <b>482913</b></p>',
    headers: { Subject: ['Votre code'], 'Content-Type': ['multipart/mixed; boundary=xyz'] },
    ...overrides,
  };
}
