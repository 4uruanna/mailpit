/**
 * Extraction d'un code ou de liens dans un mail : pratique pour les tests de bout en bout
 * (code de confirmation, lien « mot de passe oublié »…). Fonctions pures, sans requête.
 */

/**
 * Ce que lisent les fonctions d'extraction : un `Message` (mail complet), un `MessageSummary`
 * (sujet et extrait seulement), ou tout objet de cette forme.
 */
export interface MessageContent {
  subject?: string | undefined;
  snippet?: string | undefined;
  text?: string | null | undefined;
  html?: string | null | undefined;
}

/** Un mail, ou directement un texte (ou un HTML). */
export type ExtractSource = string | MessageContent;

export interface ExtractCodeOptions {
  /** Nombre de chiffres du code : 6 par défaut, ou des bornes `{ min: 4, max: 8 }`. Ignoré avec `pattern`. */
  length?: number | { min: number; max: number } | undefined;
  /**
   * Expression régulière pour un autre format (`/[A-Z0-9]{8}/`, `/code : (\w+)/i`…). Avec un groupe
   * capturant, c'est le premier groupe qui est renvoyé, sinon toute la correspondance.
   */
  pattern?: RegExp | undefined;
}

export interface ExtractLinksOptions {
  /** Ne garder que les liens qui contiennent ce texte, correspondent à cette expression, ou passent ce test. */
  filter?: string | RegExp | ((url: string) => boolean) | undefined;
}

/**
 * Premier code trouvé dans le mail : par défaut, un nombre de 6 chiffres isolé (pas au milieu d'un
 * nombre plus long). Cherche dans le texte, puis le HTML, puis l'extrait, puis le sujet.
 *
 * @returns le code, ou `null` si rien ne correspond
 */
export function extractCode(source: ExtractSource, options: ExtractCodeOptions = {}): string | null {
  const pattern = options.pattern === undefined ? digits(options.length ?? 6) : withoutState(options.pattern);
  const sources = typeof source === 'string'
    ? [looksLikeHtml(source) ? htmlToText(source) : source]
    : [source.text, source.html == null ? null : htmlToText(source.html), source.snippet, source.subject];

  for (const text of sources) {
    if (text == null || text === '') {
      continue;
    }
    const match = pattern.exec(text);
    if (match !== null) {
      return match[1] ?? match[0];
    }
  }

  return null;
}

/**
 * Liens `http(s)://` du mail, sans doublon, dans l'ordre : ceux du HTML (`href`), puis ceux du texte.
 * Il faut le mail complet (`getMessage()`, `waitForMessage()`) : l'extrait d'un résumé est tronqué.
 */
export function extractLinks(source: ExtractSource, options: ExtractLinksOptions = {}): string[] {
  const links = new Set<string>();
  const add = (url: string): void => {
    if (/^https?:\/\/./i.test(url)) {
      links.add(url);
    }
  };

  const html = typeof source === 'string' ? (looksLikeHtml(source) ? source : null) : source.html;
  const text = typeof source === 'string' ? (looksLikeHtml(source) ? null : source) : source.text;

  if (html != null) {
    for (const match of html.matchAll(/<a\b[^>]*?\shref\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+))/gi)) {
      add(decodeEntities(match[1] ?? match[2] ?? match[3] ?? '').trim());
    }
  }
  for (const value of [text, html == null ? null : htmlToText(html)]) {
    for (const match of (value ?? '').matchAll(/\bhttps?:\/\/[^\s<>"'`]+/gi)) {
      add(trimTrailingPunctuation(match[0]));
    }
  }

  const filter = options.filter;
  const result = [...links];
  if (filter === undefined) {
    return result;
  }
  if (typeof filter === 'string') {
    return result.filter((url) => url.includes(filter));
  }
  if (filter instanceof RegExp) {
    const pattern = withoutState(filter);

    return result.filter((url) => pattern.test(url));
  }

  return result.filter((url) => filter(url));
}

/** Premier lien du mail (qui passe `filter`, s'il est donné), ou `null`. Voir `extractLinks()`. */
export function extractLink(source: ExtractSource, options: ExtractLinksOptions = {}): string | null {
  return extractLinks(source, options)[0] ?? null;
}

/** Texte lisible d'un HTML : sans balises, styles, scripts ni commentaires, entités décodées. */
export function htmlToText(html: string): string {
  const withoutTags = html
    .replace(/<!--[\s\S]*?-->/g, ' ')
    .replace(/<(head|script|style|title)\b[^>]*>[\s\S]*?<\/\1\s*>/gi, ' ')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(p|div|tr|li|h[1-6]|table|blockquote)\s*>/gi, '\n')
    .replace(/<[^>]*>/g, ' ');

  return decodeEntities(withoutTags)
    .replace(/[^\S\n]+/g, ' ')
    .replace(/ ?\n[\s]*/g, '\n')
    .trim();
}

const NAMED_ENTITIES: Record<string, string> = {
  amp: '&',
  lt: '<',
  gt: '>',
  quot: '"',
  apos: "'",
  nbsp: ' ',
};

function decodeEntities(value: string): string {
  return value.replace(/&(#\d{1,7}|#x[0-9a-f]{1,6}|[a-z]+);/gi, (entity, code: string) => {
    if (code.startsWith('#')) {
      const hex = code[1] === 'x' || code[1] === 'X';
      const point = Number.parseInt(code.slice(hex ? 2 : 1), hex ? 16 : 10);

      return point <= 0x10ffff ? String.fromCodePoint(point) : entity;
    }

    return NAMED_ENTITIES[code] ?? entity;
  });
}

function looksLikeHtml(value: string): boolean {
  return /<[a-z!][^>]*>/i.test(value);
}

/** Retire la ponctuation collée à la fin d'une URL dans un texte (« … ici : https://x.fr/a. »). */
function trimTrailingPunctuation(url: string): string {
  let result = url;
  for (;;) {
    const last = result.at(-1);
    const unbalanced = (open: string, close: string): boolean =>
      last === close && result.split(open).length < result.split(close).length;
    if (last !== undefined && ('.,;:!?*'.includes(last) || unbalanced('(', ')') || unbalanced('[', ']'))) {
      result = result.slice(0, -1);
      continue;
    }

    return result;
  }
}

function digits(length: number | { min: number; max: number }): RegExp {
  const { min, max } = typeof length === 'number' ? { min: length, max: length } : length;
  if (!Number.isInteger(min) || !Number.isInteger(max) || min < 1 || max < min) {
    throw new RangeError(`extractCode : longueur invalide (${JSON.stringify(length)})`);
  }

  return new RegExp(`(?<!\\d)\\d{${min},${max}}(?!\\d)`);
}

/** Copie sans les drapeaux `g` et `y`, dont `lastIndex` rendrait les recherches successives incohérentes. */
function withoutState(pattern: RegExp): RegExp {
  return new RegExp(pattern.source, pattern.flags.replace(/[gy]/g, ''));
}
