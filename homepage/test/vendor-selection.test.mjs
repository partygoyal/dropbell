import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../backend/public/admin.js', import.meta.url), 'utf8');
function target(initial = {}) {
  const handlers = new Map();
  return {
    ...initial,
    addEventListener(name, handler) { handlers.set(name, [...(handlers.get(name) || []), handler]); },
    dispatch(name, event = {}) { for (const handler of handlers.get(name) || []) handler(event); },
  };
}
function setup() {
  const prices = [{ required: false }, { required: false }];
  const checks = prices.map(price => ({ checked: false, closest: () => ({ querySelector: () => price }) }));
  const all = target({ checked: false, indeterminate: false });
  const count = {}, state = {}, button = { disabled: false }, fieldset = { disabled: false };
  const elements = { '[data-vendor-select-all]': all, '[data-vendor-selected-count]': count, '[data-vendor-save-state]': state, '[data-vendor-save]': button, fieldset };
  const form = target({ querySelector: selector => elements[selector], querySelectorAll: () => checks });
  const window = target();
  runInNewContext(script, { document: { querySelectorAll: () => [], querySelector: selector => selector === '[data-vendor-selection]' ? form : null }, window });
  return { checks, prices, all, count, state, button, form, window };
}

test('select all survives the browser input event before its change event', () => {
  const ui = setup();
  ui.all.checked = true;
  ui.form.dispatch('input', { target: ui.all });
  ui.all.dispatch('change', { target: ui.all });
  ui.form.dispatch('change', { target: ui.all });
  assert.ok(ui.checks.every(input => input.checked));
  assert.ok(ui.prices.every(input => input.required));
  assert.equal(ui.count.textContent, '2 of 2 marked available');
  ui.all.checked = false;
  ui.form.dispatch('input', { target: ui.all });
  ui.all.dispatch('change', { target: ui.all });
  ui.form.dispatch('change', { target: ui.all });
  assert.ok(ui.checks.every(input => !input.checked));
  assert.equal(ui.count.textContent, '0 of 2 marked available');
});

test('individual selection updates the count and requires only its price', () => {
  const ui = setup();
  ui.checks[0].checked = true;
  ui.form.dispatch('input', { target: ui.checks[0] });
  assert.equal(ui.all.indeterminate, true);
  assert.equal(ui.count.textContent, '1 of 2 marked available');
  assert.equal(ui.prices[0].required, true);
  assert.equal(ui.prices[1].required, false);
});

test('unsaved selection warns before leaving and duplicate submissions are blocked', () => {
  const ui = setup();
  let warned = false;
  ui.form.dispatch('input', { target: ui.checks[0] });
  ui.window.dispatch('beforeunload', { preventDefault: () => { warned = true; } });
  assert.equal(warned, true);
  ui.form.dispatch('submit', {});
  assert.equal(ui.button.disabled, true);
  let duplicateBlocked = false;
  ui.form.dispatch('submit', { preventDefault: () => { duplicateBlocked = true; } });
  assert.equal(duplicateBlocked, true);
  ui.window.dispatch('pageshow');
  assert.equal(ui.button.disabled, false);
});
