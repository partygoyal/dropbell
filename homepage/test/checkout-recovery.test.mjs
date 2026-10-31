import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readCheckoutAttempt, saveCheckoutAttempt } from '../app/lib/checkout-recovery.mjs';

function storage() {
  const values = new Map();
  return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) };
}
const attempt = { endpoint: 'orders', payload: { request_key: '00000000-0000-4000-8000-000000000001', items: [{ id: 1, quantity: 1 }], address: { locality_id: 1 }, expected_total: 15000 } };

test('reload restores the exact checkout payload and request key', () => {
  const disk = storage();
  saveCheckoutAttempt(disk, 'customer:one', attempt);
  assert.deepEqual(readCheckoutAttempt(disk, 'customer:one'), attempt);
});

test('an uncertain checkout cannot be overwritten by a changed bag', () => {
  const disk = storage();
  saveCheckoutAttempt(disk, 'customer:one', attempt);
  const next = { ...attempt, payload: { ...attempt.payload, request_key: '00000000-0000-4000-8000-000000000002', items: [{ id: 2, quantity: 9 }] } };
  assert.deepEqual(saveCheckoutAttempt(disk, 'customer:one', next), attempt);
  assert.deepEqual(readCheckoutAttempt(disk, 'customer:one'), attempt);
});

test('checkout attempts are scoped to each customer', () => {
  const disk = storage();
  saveCheckoutAttempt(disk, 'customer:one', attempt);
  assert.equal(readCheckoutAttempt(disk, 'customer:two'), null);
});

test('corrupted pending checkout blocks replacement', () => {
  const disk = storage();
  disk.setItem('customer:one', '{"endpoint":"orders","payload":{}}');
  assert.throws(() => saveCheckoutAttempt(disk, 'customer:one', attempt), /could not be read/);
});

test('storage failure prevents checkout reservation', () => {
  const disk = storage();
  disk.setItem = () => { throw new Error('Storage full'); };
  assert.throws(() => saveCheckoutAttempt(disk, 'customer:one', attempt), /Storage full/);
});
