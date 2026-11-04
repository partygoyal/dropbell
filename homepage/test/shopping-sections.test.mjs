import test from 'node:test';
import assert from 'node:assert/strict';
import { sectionCatalogue, sectionPromotions } from '../app/lib/shopping-sections.mjs';

test('food and grocery browsing separate categories, menus and vendor counts without removing bag products', () => {
  const products = Object.freeze([Object.freeze({ id: 1, section: 'groceries' }), Object.freeze({ id: 2, section: 'food' })]);
  const categories = [{ id: 1, shopping_section: 'groceries' }, { id: 2, shopping_section: 'food' }];
  const subCategories = [{ id: 10, category_id: 1 }, { id: 20, category_id: 2 }];
  const vendors = [{ id: 1, sections: ['groceries'], product_count: 8 }, { id: 2, sections: ['food', 'groceries'], product_count: 15, section_counts: { food: 5, groceries: 10 } }];
  const food = sectionCatalogue({ products, categories, subCategories, vendors }, 'food');
  assert.deepEqual(food.products.map(item => item.id), [2]);
  assert.deepEqual(food.categories.map(item => item.id), [2]);
  assert.deepEqual(food.subCategories.map(item => item.id), [20]);
  assert.deepEqual(food.vendors.map(item => [item.id, item.product_count]), [[2, 5]]);
  assert.equal(products.length, 2);
  assert.equal(vendors[1].product_count, 15);
  const groceries = sectionCatalogue({ products, categories, subCategories, vendors }, 'groceries');
  assert.deepEqual(groceries.products.map(item => item.id), [1]);
  assert.deepEqual(groceries.vendors.map(item => item.product_count), [8, 10]);
});

test('existing catalogue records default to groceries rather than appearing as restaurant menus', () => {
  const data = { products: [{ id: 1 }], categories: [{ id: 1 }], subCategories: [], vendors: [{ id: 1 }] };
  assert.equal(sectionCatalogue(data, 'food').products.length, 0);
  assert.equal(sectionCatalogue(data, 'food').vendors.length, 0);
  assert.equal(sectionCatalogue(data, 'groceries').products.length, 1);
});

test('food banners exclude grocery campaigns and include shared offers', () => {
  const offers = [{ id: 1 }, { id: 2, banner_section: 'food' }, { id: 3, banner_section: 'groceries' }, { id: 4, banner_section: 'all' }];
  assert.deepEqual(sectionPromotions(offers, 'food').map(item => item.id), [2, 4]);
  assert.deepEqual(sectionPromotions(offers, 'groceries').map(item => item.id), [1, 3, 4]);
});
