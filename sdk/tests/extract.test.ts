import { describe, expect, it } from 'vitest';
import { extractCode, extractLink, extractLinks } from '../src/index.js';
import { htmlToText } from '../src/extract.js';

describe('extractCode', () => {
  it('un code à 6 chiffres dans le texte', () => {
    expect(extractCode({ text: 'Bonjour,\nVotre code de confirmation : 482913.\nÀ bientôt' })).toBe('482913');
  });

  it('ignore les nombres plus longs ou plus courts', () => {
    expect(extractCode({ text: 'Appelez le 0612345678 ou le 3615. Code : 004521' })).toBe('004521');
    expect(extractCode({ text: 'Commande 1234567' })).toBeNull();
  });

  it('cherche dans le HTML si le texte est absent, sans lire les styles', () => {
    const html = '<html><head><style>.a { color: #123456 }</style></head><body><!-- 111111 --><p>Code : <b>654321</b></p></body></html>';

    expect(extractCode({ text: null, html })).toBe('654321');
  });

  it('puis dans l’extrait et le sujet (résumé de la liste)', () => {
    expect(extractCode({ subject: 'Votre code', snippet: 'Votre code de confirmation : 482913' })).toBe('482913');
    expect(extractCode({ subject: 'Code 777777', snippet: 'Bonjour' })).toBe('777777');
  });

  it('longueur fixe ou bornes', () => {
    expect(extractCode({ text: 'PIN : 4821' }, { length: 4 })).toBe('4821');
    expect(extractCode({ text: 'Code : 12345678' }, { length: { min: 4, max: 8 } })).toBe('12345678');
    expect(() => extractCode({ text: '' }, { length: 0 })).toThrow(RangeError);
    expect(() => extractCode({ text: '' }, { length: { min: 6, max: 4 } })).toThrow(RangeError);
  });

  it('expression personnalisée : premier groupe capturant, sinon toute la correspondance', () => {
    const message = { text: 'Votre code : AB12-CD34 (valable 10 minutes)' };

    expect(extractCode(message, { pattern: /code : ([A-Z0-9-]+)/i })).toBe('AB12-CD34');
    expect(extractCode(message, { pattern: /[A-Z]{2}\d{2}-[A-Z]{2}\d{2}/ })).toBe('AB12-CD34');
  });

  it('une expression avec le drapeau g donne le même résultat à chaque appel', () => {
    const pattern = /\d{3}/g;
    const message = { text: 'code 123' };

    expect(extractCode(message, { pattern })).toBe('123');
    expect(extractCode(message, { pattern })).toBe('123');
  });

  it('accepte directement un texte ou un HTML', () => {
    expect(extractCode('Code : 135790')).toBe('135790');
    expect(extractCode('<p style="color:#000000">Code : <b>246802</b></p>')).toBe('246802');
  });

  it('null si rien ne correspond', () => {
    expect(extractCode({ text: 'Bienvenue !', html: null, snippet: '', subject: 'Bienvenue' })).toBeNull();
  });
});

describe('extractLinks', () => {
  const message = {
    text: 'Réinitialisez votre mot de passe : https://app.mondomaine.fr/reset?token=abc&lang=fr.\n'
      + 'Aide (https://aide.mondomaine.fr/faq) ou https://fr.wikipedia.org/wiki/Test_(informatique)',
    html: '<p><a href="https://app.mondomaine.fr/reset?token=abc&amp;lang=fr">Réinitialiser</a>'
      + ' <a class="x" href=\'https://app.mondomaine.fr/desinscription\'>Se désinscrire</a>'
      + ' <a href="mailto:aide@mondomaine.fr">Écrire</a> <a href="#haut">Haut</a></p>',
  };

  it('liens du HTML puis du texte, entités décodées, sans doublon', () => {
    expect(extractLinks(message)).toEqual([
      'https://app.mondomaine.fr/reset?token=abc&lang=fr',
      'https://app.mondomaine.fr/desinscription',
      'https://aide.mondomaine.fr/faq',
      'https://fr.wikipedia.org/wiki/Test_(informatique)',
    ]);
  });

  it('filtre par texte, expression ou fonction', () => {
    expect(extractLinks(message, { filter: '/reset' })).toEqual(['https://app.mondomaine.fr/reset?token=abc&lang=fr']);
    expect(extractLinks(message, { filter: /desinscription$/g })).toEqual(['https://app.mondomaine.fr/desinscription']);
    expect(extractLinks(message, { filter: (url) => new URL(url).hostname === 'aide.mondomaine.fr' }))
      .toEqual(['https://aide.mondomaine.fr/faq']);
  });

  it('URL nue dans un HTML sans lien', () => {
    expect(extractLinks({ html: '<p>Copiez : https://app.mondomaine.fr/confirmer?c=1&amp;d=2</p>' }))
      .toEqual(['https://app.mondomaine.fr/confirmer?c=1&d=2']);
  });

  it('accepte directement un texte ou un HTML', () => {
    expect(extractLinks('Voir https://a.fr/x, puis https://b.fr/y!')).toEqual(['https://a.fr/x', 'https://b.fr/y']);
    expect(extractLinks('<a href="https://a.fr/x">a</a>')).toEqual(['https://a.fr/x']);
  });

  it('aucun lien', () => {
    expect(extractLinks({ text: null, html: null })).toEqual([]);
  });
});

describe('extractLink', () => {
  it('le premier lien, ou null', () => {
    expect(extractLink({ text: 'https://a.fr/1 https://b.fr/2' })).toBe('https://a.fr/1');
    expect(extractLink({ text: 'https://a.fr/1 https://b.fr/2' }, { filter: 'b.fr' })).toBe('https://b.fr/2');
    expect(extractLink({ text: 'rien' })).toBeNull();
  });
});

describe('htmlToText', () => {
  it('retire balises, styles, scripts et commentaires, et décode les entités', () => {
    const html = '<html><head><title>T</title></head><body><script>var a = 1;</script>'
      + '<p>Bonjour&nbsp;Alice,</p><p>A &amp; B &lt;3 &#233;t&#xE9;</p><!-- caché --><br>Fin</body></html>';

    expect(htmlToText(html)).toBe('Bonjour Alice,\nA & B <3 été\nFin');
  });
});
