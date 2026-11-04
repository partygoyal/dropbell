import { test } from 'node:test';
import assert from 'node:assert/strict';
import { homeProductGroups } from '../app/lib/home-products.mjs';
const products = [
  { id: 1, name: 'Milk', cat: 'Dairy', qty: '1 litre', price: 60, inventory: 4, sample: false },
  { id: 2, name: 'Apple', cat: 'Fruit', qty: '1 kg', price: 100, inventory: 8, sample: false },
  { id: 3, name: 'Curd', cat: 'Dairy', qty: '500 g', price: 30, inventory: null, sample: false },
  { id: 4, name: 'Banana', cat: 'Fruit', qty: '6 pieces', price: 40, inventory: 20, sample: true },
  { id: 5, name: 'Butter', cat: 'Dairy', qty: '100 g', price: 50, inventory: 0, sample: false },
];
test('homepage shelves group products without mixing categories', () => {
  const rows = homeProductGroups(products);
  assert.deepEqual(rows.map(row => row.name), ['Dairy', 'Fruit']);
  assert.deepEqual(rows[0].products.map(item => item.id), [1, 3, 5]);
});
test('category and case-insensitive search work together', () => {
  assert.deepEqual(homeProductGroups(products, { category: 'Dairy', search: ' MILK ' })[0].products.map(item => item.id), [1]);
  assert.deepEqual(homeProductGroups(products, { category: 'Fruit', search: 'Milk' }), []);
});
test('price sorting stays within shelves and never mutates the original catalogue', () => {
  const rows = homeProductGroups(products, { sort: 'price-low' });
  assert.deepEqual(rows.map(row => row.name), ['Dairy', 'Fruit']);
  assert.deepEqual(rows[0].products.map(item => item.id), [3, 5, 1]);
  assert.deepEqual(products.map(item => item.id), [1, 2, 3, 4, 5]);
});
test('shop-product filter excludes samples and sold-out products but supports untracked stock', () => {
  assert.deepEqual(homeProductGroups(products, { availability: 'orderable' }).flatMap(row => row.products).map(item => item.id), [1, 3, 2]);
});
