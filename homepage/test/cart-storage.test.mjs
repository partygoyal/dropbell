import { test } from 'node:test';
import assert from 'node:assert/strict';
import { saveCart, consumeCheckout, getCartVendor, switchCartVendor } from '../app/lib/cart-storage.mjs';

function storage(initial) {
  let value = JSON.stringify(initial);
  return { getItem: () => value, setItem: (_, next) => { value = next; } };
}

test('checkout cleanup preserves items added after checkout started', () => {
  const disk = storage({ 1: 4, 2: 3 });
  assert.deepEqual(consumeCheckout(disk, 'checkout-one', [{ id: 1, quantity: 2 }]), { 1: 2, 2: 3 });
});

test('recovery after cleanup does not subtract purchased items twice', () => {
  const disk = storage({ 1: 4 });
  consumeCheckout(disk, 'checkout-one', [{ id: 1, quantity: 2 }]);
  saveCart(disk, { 1: 3, 2: 1 });
  assert.deepEqual(consumeCheckout(disk, 'checkout-one', [{ id: 1, quantity: 2 }]), { 1: 3, 2: 1 });
});

test('legacy carts are supported and invalid quantities are ignored', () => {
  const disk = storage({ 1: 2, 2: -1, other: 8 });
  assert.deepEqual(consumeCheckout(disk, 'checkout-one', [{ id: 1, quantity: 2 }]), {});
});

test('shop choice and an empty bag are saved together and survive edits', () => {
  const disk = storage({ 1: 4 });
  switchCartVendor(disk, 12);
  assert.equal(getCartVendor(disk), 12);
  assert.equal(JSON.parse(disk.getItem())[1], undefined);
  saveCart(disk, { 2: 3 });
  assert.equal(getCartVendor(disk), 12);
  assert.equal(JSON.parse(disk.getItem())[2], 3);
});

test('recovering an old checkout cannot remove items from another shop bag', () => {
  const disk = storage({});
  switchCartVendor(disk, 20);
  saveCart(disk, { 1: 4 });
  assert.deepEqual(consumeCheckout(disk, 'old-shop-checkout', [{ id: 1, quantity: 2 }], 10), { 1: 4 });
});

test('chosen shop checkout cleans up once and switching back clears its scope', () => {
  const disk = storage({});
  switchCartVendor(disk, 12);
  saveCart(disk, { 1: 4 });
  assert.deepEqual(consumeCheckout(disk, 'shop-checkout', [{ id: 1, quantity: 2 }], 12), { 1: 2 });
  assert.deepEqual(consumeCheckout(disk, 'shop-checkout', [{ id: 1, quantity: 2 }], 12), { 1: 2 });
  switchCartVendor(disk, null);
  assert.equal(getCartVendor(disk), null);
});
