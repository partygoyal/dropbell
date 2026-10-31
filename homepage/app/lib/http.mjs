export class ApiError extends Error {
  constructor(message, status = 0) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
  }
}

// Writes are never retried automatically: the server may have accepted them.
export async function fetchJson(url, options = {}) {
  const { timeoutMs = 20000, ...init } = options;
  const controller = new AbortController();
  const abort = () => controller.abort(init.signal?.reason);
  if (init.signal?.aborted) abort();
  else init.signal?.addEventListener('abort', abort, { once: true });
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const response = await fetch(url, { ...init, signal: controller.signal });
    let body;
    try { body = await response.json(); } catch {
      if (controller.signal.aborted) throw new DOMException('Aborted', 'AbortError');
      throw new ApiError('The server returned an unreadable response. Please try again shortly.', response.status);
    }
    if (!body || typeof body !== 'object' || Array.isArray(body)) {
      throw new ApiError('The server returned an unexpected response. Please try again shortly.', response.status);
    }
    if (!response.ok) {
      const validation = body.errors && typeof body.errors === 'object'
        ? Object.values(body.errors).flat().find(value => typeof value === 'string' && value.length) : null;
      const fallback = response.status === 429 ? 'Too many requests. Please wait a moment before trying again.'
        : response.status >= 500 ? 'Dropbell is temporarily unavailable. Please try again shortly.'
        : 'Unable to complete this request. Please try again.';
      throw new ApiError(response.status >= 500 ? fallback : validation || (typeof body.message === 'string' && body.message) || fallback, response.status);
    }
    return body;
  } catch (error) {
    if (init.signal?.aborted) throw error;
    if (controller.signal.aborted) throw new ApiError('The request timed out. Check your orders before placing an order again.');
    if (error instanceof ApiError) throw error;
    throw new ApiError('Cannot reach Dropbell. Check your connection and try again.');
  } finally {
    clearTimeout(timer);
    init.signal?.removeEventListener('abort', abort);
  }
}
