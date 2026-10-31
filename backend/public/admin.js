(() => {
  const menu = document.querySelector('[data-notifications-url]');
  if (!menu) return;
  const badge = menu.querySelector('.notification-count');
  const state = menu.querySelector('.notification-state');
  const list = menu.querySelector('.notification-list');
  let latestId = null;
  let loading = false;
  async function refresh() {
    if (loading) return;
    loading = true;
    try {
      const response = await fetch(menu.dataset.notificationsUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (!response.ok) throw new Error('Could not refresh orders.');
      const data = await response.json();
      badge.textContent = data.count;
      badge.hidden = data.count === 0;
      state.textContent = data.count ? `${data.count} awaiting acceptance` : 'You’re all caught up. No new orders.';
      const links = data.orders.map(order => {
        const link = document.createElement('a');
        link.href = order.url;
        const title = document.createElement('b');
        title.textContent = order.reference;
        const description = document.createElement('span');
        description.textContent = `${order.total} · Open order →`;
        link.append(title, description);
        return link;
      });
      list.replaceChildren(...links);
      const newest = data.orders[0]?.id || 0;
      if (latestId !== null && newest > latestId) menu.open = true;
      latestId = Math.max(latestId || 0, newest);
    } catch { state.textContent = 'Could not refresh notifications. Try opening the menu again.'; }
    finally { loading = false; }
  }
  menu.addEventListener('toggle', () => { if (menu.open) refresh(); });
  document.addEventListener('click', event => { if (!menu.contains(event.target)) menu.open = false; });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') menu.open = false; });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  refresh();
  setInterval(() => { if (!document.hidden) refresh(); }, 15000);
})();
(() => {
  const category = document.querySelector('#product-category');
  const subCategory = document.querySelector('#product-sub-category');
  if (!category || !subCategory) return;
  function filter() {
    for (const option of subCategory.options) {
      option.hidden = !!option.value && !!category.value && option.dataset.category !== category.value;
      option.disabled = option.hidden;
      if (option.hidden && option.selected) subCategory.value = '';
    }
  }
  category.addEventListener('change', filter);
  filter();
})();
(() => {
  const form = document.querySelector('[data-order-editor]');
  if (!form) return;
  const summary = form.querySelector('[data-order-total]');
  const format = amount => new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(amount / 100);
  function preview() {
    let subtotal = [...form.querySelectorAll('[data-order-price]')].reduce((total, input) => total + Number(input.dataset.orderPrice) * Math.max(0, Number(input.value) || 0), 0);
    const addedProduct = form.elements.add_product_id.selectedOptions[0];
    subtotal += Number(addedProduct.dataset.price || 0) * Math.max(0, Number(form.elements.add_quantity.value) || 1);
    const discount = form.dataset.discount === '1' && subtotal >= 10000 ? Math.min(5000, Math.round(subtotal * .1)) : 0;
    const configuration = JSON.parse(form.querySelector('[data-delivery-rules]').value);
    const postalCode = form.elements['address[pin]'].value;
    const localityId = form.elements['address[locality_id]']?.value;
    const locality = configuration.localities?.find(area => String(area.id) === localityId);
    const rules = localityId ? (String(configuration.locality_id) === localityId && configuration.saved ? configuration.saved : locality ? { ...locality, minimum_paise: 0, free_above_paise: null } : null) : postalCode === configuration.postal_code && configuration.saved ? configuration.saved : configuration.areas.find(area => area.postal_code === postalCode);
    if (!rules) { summary.textContent = 'This postal code is not serviceable. Choose an active delivery area before saving.'; return; }
    if (subtotal < rules.minimum_paise) { summary.textContent = `Minimum items subtotal for ${rules.name}: ${format(rules.minimum_paise)}.`; return; }
    const delivery = rules.free_above_paise !== null && subtotal >= rules.free_above_paise ? 0 : rules.fee_paise;
    summary.textContent = `Items ${format(subtotal)} · Savings ${format(discount)} · Delivery ${format(delivery)} · Total ${format(subtotal - discount + delivery)}`;
  }
  form.addEventListener('input', preview);
  form.addEventListener('change', preview);
  preview();
})();

(() => {
  const form = document.querySelector('[data-vendor-selection]');
  if (!form) return;
  const checks = [...form.querySelectorAll('[data-vendor-available]')];
  const all = form.querySelector('[data-vendor-select-all]');
  const count = form.querySelector('[data-vendor-selected-count]');
  const state = form.querySelector('[data-vendor-save-state]');
  let dirty = false;
  let saving = false;
  function refresh() {
    const selected = checks.filter(input => input.checked).length;
    count.textContent = `${selected} of ${checks.length} marked available`;
    all.checked = selected === checks.length;
    all.indeterminate = selected > 0 && selected < checks.length;
    for (const input of checks) input.closest('article').querySelector('[data-vendor-price]').required = input.checked;
  }
  all.addEventListener('change', () => { for (const input of checks) input.checked = all.checked; refresh(); });
  form.addEventListener('input', event => { dirty = true; state.textContent = 'Unsaved changes'; if (event.target !== all) refresh(); });
  form.addEventListener('change', () => { dirty = true; state.textContent = 'Unsaved changes'; refresh(); });
  window.addEventListener('beforeunload', event => { if (dirty && !saving) { event.preventDefault(); event.returnValue = ''; } });
  form.addEventListener('submit', event => {
    if (saving) { event.preventDefault(); return; }
    saving = true;
    const button = form.querySelector('[data-vendor-save]');
    button.disabled = true;
    button.textContent = 'Saving availability…';
  });
  // Restore controls when the browser returns to this page from its back cache.
  window.addEventListener('pageshow', () => { saving = false; const button = form.querySelector('[data-vendor-save]'); button.disabled = form.querySelector('fieldset').disabled; button.textContent = 'Save availability'; });
  refresh();
})();

(() => {
  const root = document.querySelector('[data-order-details]');
  if (!root) return;
  const tabs = [...root.querySelectorAll('[data-order-tab]')];
  const panels = [...root.querySelectorAll('[data-order-panel]')];
  const nav = root.querySelector('.order-tabs');
  nav.setAttribute('role', 'tablist');
  for (const tab of tabs) {
    tab.id = `order-tab-${tab.dataset.orderTab}`;
    tab.setAttribute('role', 'tab');
    tab.setAttribute('aria-controls', tab.dataset.orderTab);
  }
  for (const panel of panels) {
    panel.setAttribute('role', 'tabpanel');
    panel.setAttribute('aria-labelledby', `order-tab-${panel.dataset.orderPanel}`);
    panel.tabIndex = 0;
  }
  function select(name, focus = false) {
    if (!tabs.some(tab => tab.dataset.orderTab === name)) name = 'overview';
    for (const tab of tabs) {
      const active = tab.dataset.orderTab === name;
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
      if (active && focus) tab.focus();
    }
    for (const panel of panels) panel.hidden = panel.dataset.orderPanel !== name;
  }
  function activate(tab) {
    const name = tab.dataset.orderTab;
    history.replaceState(null, '', `#${name}`);
    select(name, true);
  }
  for (const [index, tab] of tabs.entries()) {
    tab.addEventListener('click', event => { event.preventDefault(); activate(tab); });
    tab.addEventListener('keydown', event => {
      let next;
      if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
      if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
      if (event.key === 'Home') next = 0;
      if (event.key === 'End') next = tabs.length - 1;
      if (next !== undefined) { event.preventDefault(); activate(tabs[next]); }
    });
  }
  window.addEventListener('hashchange', () => select(location.hash.slice(1)));
  select(location.hash.slice(1));
})();
