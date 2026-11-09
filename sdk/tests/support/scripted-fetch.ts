export interface FetchCall {
  url: URL;
  init: RequestInit;
  headers: Headers;
}

export type Step = Response | Error | ((call: FetchCall) => Response | Promise<Response>);

/**
 * `fetch` qui répond, appel après appel, avec les étapes données : une réponse, une erreur levée
 * (panne réseau), ou une fonction. Un appel de trop fait échouer le test.
 */
export function scriptedFetch(...steps: Step[]): { fetch: typeof globalThis.fetch; calls: FetchCall[] } {
  const calls: FetchCall[] = [];
  const fetch: typeof globalThis.fetch = async (input, init = {}) => {
    const call = { url: new URL(input instanceof Request ? input.url : String(input)), init, headers: new Headers(init.headers) };
    calls.push(call);
    const step = steps[calls.length - 1];
    if (step === undefined) {
      throw new Error(`requête inattendue n° ${calls.length} : ${init.method ?? 'GET'} ${call.url.pathname}`);
    }
    if (step instanceof Error) {
      throw step;
    }

    return typeof step === 'function' ? step(call) : step;
  };

  return { fetch, calls };
}

/** Panne réseau telle que la lève le fetch de Node (undici) : TypeError, cause avec un code. */
export function networkError(code = 'ECONNREFUSED'): TypeError {
  return new TypeError('fetch failed', { cause: Object.assign(new Error(`connect ${code} 203.0.113.10:443`), { code }) });
}
