import { test, afterEach, mock } from 'node:test';
import assert from 'node:assert/strict';
import { fetchJson, ApiError } from '../app/lib/http.mjs';

afterEach(() => mock.restoreAll());

test('returns valid JSON and preserves request body', async () => {
  mock.method(globalThis, 'fetch', async (_, init) => {
    assert.equal(init.body, '{"quantity":1}');
    return Response.json({ data: { id: 'order-1' } });
  });
  assert.deepEqual(await fetchJson('/api/orders', { method: 'POST', body: '{"quantity":1}' }), { data: { id: 'order-1' } });
});

test('malformed JSON produces a useful error with HTTP status', async () => {
  mock.method(globalThis, 'fetch', async () => new Response('<html>Maintenance</html>', { status: 503 }));
  await assert.rejects(fetchJson('/api/me'), error => error instanceof ApiError && error.status === 503);
});

test('unexpected successful response is rejected', async () => {
  mock.method(globalThis, 'fetch', async () => Response.json(null));
  await assert.rejects(fetchJson('/api/me'), /unexpected response/);
});

test('validation errors retain useful messages', async () => {
  mock.method(globalThis, 'fetch', async () => Response.json({ errors: { phone: ['Enter a valid phone number.'] } }, { status: 422 }));
  await assert.rejects(fetchJson('/api/me'), { message: 'Enter a valid phone number.', status: 422 });
});

test('server errors do not expose internal details', async () => {
  mock.method(globalThis, 'fetch', async () => Response.json({ message: 'SQLSTATE password=secret' }, { status: 500 }));
  await assert.rejects(fetchJson('/api/me'), { message: 'Dropbell is temporarily unavailable. Please try again shortly.', status: 500 });
});

test('timeout covers waiting for response and never automatically repeats a write', async () => {
  let calls = 0;
  mock.method(globalThis, 'fetch', (_, { signal }) => new Promise((resolve, reject) => {
    calls++;
    signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
  }));
  await assert.rejects(fetchJson('/api/orders', { method: 'POST', timeoutMs: 5 }), /timed out/);
  assert.equal(calls, 1);
});

test('caller cancellation remains distinguishable from a network failure', async () => {
  mock.method(globalThis, 'fetch', (_, { signal }) => new Promise((resolve, reject) => {
    signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
  }));
  const controller = new AbortController();
  const result = fetchJson('/api/catalog', { signal: controller.signal });
  controller.abort();
  await assert.rejects(result, { name: 'AbortError' });
});

test('network failure receives a connection message', async () => {
  mock.method(globalThis, 'fetch', async () => { throw new TypeError('Failed to fetch'); });
  await assert.rejects(fetchJson('/api/me'), /Check your connection/);
});
