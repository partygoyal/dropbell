import test from 'node:test';
import assert from 'node:assert/strict';
import { startPromotionPolling } from '../app/lib/promotion-polling.mjs';

const settle = () => new Promise(resolve => setImmediate(resolve));

test('cleanup ignores a late response without aborting the request', async () => {
  let resolve;
  const updates = [];
  const stop = startPromotionPolling(items => updates.push(items), {
    request: (url, options) => {
      assert.equal(url, '/api/promotions');
      assert.equal(options?.signal, undefined);
      return new Promise(done => { resolve = done; });
    },
  });
  stop();
  resolve({ data: [{ id: 1 }] });
  await settle();
  assert.deepEqual(updates, []);
});

test('refreshes never overlap and cleanup stops future polling', async t => {
  t.mock.timers.enable({ apis: ['setInterval'] });
  let calls = 0;
  let resolve;
  const stop = startPromotionPolling(() => {}, {
    request: () => { calls++; return new Promise(done => { resolve = done; }); },
  });
  try {
    t.mock.timers.tick(180000);
    assert.equal(calls, 1);
    resolve({ data: [] });
    await settle();
    t.mock.timers.tick(60000);
    assert.equal(calls, 2);
    stop();
    resolve({ data: [] });
    await settle();
    t.mock.timers.tick(180000);
    assert.equal(calls, 2);
  } finally { stop(); }
});

test('a failed refresh preserves offers and later polling recovers', async t => {
  t.mock.timers.enable({ apis: ['setInterval'] });
  let calls = 0;
  const updates = [];
  const stop = startPromotionPolling(items => updates.push(items), {
    request: async () => { if (++calls === 2) throw new Error('Offline'); return { data: [{ id: calls }] }; },
  });
  try {
    await settle();
    t.mock.timers.tick(60000);
    await settle();
    assert.deepEqual(updates, [[{ id: 1 }]]);
    t.mock.timers.tick(60000);
    await settle();
    assert.deepEqual(updates, [[{ id: 1 }], [{ id: 3 }]]);
  } finally { stop(); }
});
