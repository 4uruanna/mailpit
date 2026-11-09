/**
 * Erreurs du SDK. Toutes héritent d'`AmnesicMailError` :
 *
 *   AmnesicMailError
 *   ├── ApiError                 réponse HTTP d'erreur de l'API (status, body…)
 *   │   ├── BadRequestError      400 : paramètre invalide
 *   │   ├── AuthenticationError  401 : jeton absent, inconnu ou supprimé
 *   │   ├── PermissionDeniedError 403 : adresse non associée au jeton
 *   │   └── NotFoundError        404 : mail inconnu, ou reçu par aucune adresse du jeton
 *   ├── ConnectionError          l'API est injoignable (DNS, connexion refusée…)
 *   ├── RequestTimeoutError      pas de réponse dans le délai (`timeout`)
 *   └── WaitTimeoutError         `waitForMessage()` : aucun mail à temps
 *
 * Le jeton n'apparaît jamais dans les messages d'erreur.
 */

export class AmnesicMailError extends Error {
  static {
    this.prototype.name = 'AmnesicMailError';
  }
}

export interface ApiErrorDetails {
  status: number;
  method: string;
  /** Chemin appelé, avec ses paramètres (`/v1/messages?address=…`), sans l'URL de base. */
  path: string;
  /** Corps de la réponse : JSON décodé, texte brut sinon, `null` s'il est vide. */
  body: unknown;
  /** Nombre d'essais, relances comprises. */
  attempts: number;
}

/** L'API a répondu avec un code d'erreur. */
export class ApiError extends AmnesicMailError {
  static {
    this.prototype.name = 'ApiError';
  }
  readonly status: number;
  readonly method: string;
  readonly path: string;
  readonly body: unknown;
  readonly attempts: number;
  /** Message d'erreur de l'API (champ `error` ou `message` du JSON), ou `null`. */
  readonly apiMessage: string | null;

  constructor(details: ApiErrorDetails) {
    const apiMessage = ApiError.#messageOf(details.body);
    super(`${details.method} ${details.path} : HTTP ${details.status}${ApiError.#explain(details.status, apiMessage)}`);
    this.status = details.status;
    this.method = details.method;
    this.path = details.path;
    this.body = details.body;
    this.attempts = details.attempts;
    this.apiMessage = apiMessage;
  }

  /** L'erreur la plus précise pour ce code HTTP. */
  static from(details: ApiErrorDetails): ApiError {
    switch (details.status) {
      case 400:
        return new BadRequestError(details);
      case 401:
        return new AuthenticationError(details);
      case 403:
        return new PermissionDeniedError(details);
      case 404:
        return new NotFoundError(details);
      default:
        return new ApiError(details);
    }
  }

  static #messageOf(body: unknown): string | null {
    if (typeof body !== 'object' || body === null) {
      return null;
    }
    const { error, message } = body as { error?: unknown; message?: unknown };
    if (typeof error === 'string' && error !== '') {
      return error;
    }

    return typeof message === 'string' && message !== '' ? message : null;
  }

  static #explain(status: number, apiMessage: string | null): string {
    // 401 et 403 génériques : le message de l'API (« Unauthorized », « Forbidden ») n'aide pas.
    if (status === 401) {
      return ' – jeton absent, inconnu ou supprimé';
    }
    if (status >= 300 && status < 400) {
      return ' – redirection non suivie : vérifiez baseUrl (https://, sans /v1)';
    }
    if (status === 403 && (apiMessage === null || apiMessage === 'Forbidden')) {
      return ' – accès refusé (jeton du relais hors des IP autorisées ?)';
    }

    return apiMessage === null ? '' : ` – ${apiMessage}`;
  }
}

/** 400 : paramètre invalide (`limit` hors bornes, `since` sans fuseau…). */
export class BadRequestError extends ApiError {
  static {
    this.prototype.name = 'BadRequestError';
  }
}

/** 401 : jeton absent, inconnu ou supprimé. */
export class AuthenticationError extends ApiError {
  static {
    this.prototype.name = 'AuthenticationError';
  }
}

/** 403 : adresse non associée au jeton. */
export class PermissionDeniedError extends ApiError {
  static {
    this.prototype.name = 'PermissionDeniedError';
  }
}

/** 404 : mail inconnu, ou reçu par aucune des adresses du jeton (l'API ne distingue pas). */
export class NotFoundError extends ApiError {
  static {
    this.prototype.name = 'NotFoundError';
  }
}

/** L'API est injoignable : DNS, connexion refusée ou coupée, certificat invalide… */
export class ConnectionError extends AmnesicMailError {
  static {
    this.prototype.name = 'ConnectionError';
  }
}

/** L'API n'a pas répondu dans le délai. */
export class RequestTimeoutError extends AmnesicMailError {
  static {
    this.prototype.name = 'RequestTimeoutError';
  }
  /** Délai dépassé, en millisecondes. */
  readonly timeout: number;

  constructor(message: string, timeout: number) {
    super(message);
    this.timeout = timeout;
  }
}

/** `waitForMessage()` : aucun mail correspondant n'est arrivé à temps. */
export class WaitTimeoutError extends AmnesicMailError {
  static {
    this.prototype.name = 'WaitTimeoutError';
  }
  /** Durée de l'attente, en millisecondes. */
  readonly timeout: number;

  constructor(message: string, timeout: number) {
    super(message);
    this.timeout = timeout;
  }
}
