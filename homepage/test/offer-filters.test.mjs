import test from 'node:test';
import assert from 'node:assert/strict';
import { filterOffers } from '../app/lib/offer-filters.mjs';
const offers = [
  { id: 1, title: 'All orders', code: 'SAVE10', scope: 'all_items', starts_at: '2026-11-01 00:00:00', ends_at: '2026-11-06 00:00:00' },
  { id: 2, title: 'Paneer offer', scope: 'selected_items', category_ids: [4], product_ids: [8], starts_at: null, ends_at: null },
];
test('category and item filtering retains genuinely order-wide coupons', () => {
  assert.deepEqual(filterOffers(offers, { category: '4', item: '8' }).map(o => o.id), [1, 2]);
  assert.deepEqual(filterOffers(offers, { category: '5', item: '9' }).map(o => o.id), [1]);
});
test('date ranges use IST and overlap offer validity, with open-ended offers retained', () => {
  assert.deepEqual(filterOffers(offers, { from: '2026-11-06', to: '2026-11-06' }).map(o => o.id), [1, 2]);
  assert.deepEqual(filterOffers(offers, { from: '2026-11-07' }).map(o => o.id), [2]);
  assert.deepEqual(filterOffers(offers, { to: '2026-10-31' }).map(o => o.id), [2]);
});
test('search finds coupon codes regardless of case', () => {
  assert.deepEqual(filterOffers(offers, { query: 'save10' }).map(o => o.id), [1]);
});
