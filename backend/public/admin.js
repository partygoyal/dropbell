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
    const rules = postalCode === configuration.postal_code && configuration.saved ? configuration.saved : configuration.areas.find(area => area.postal_code === postalCode);
    if (!rules) { summary.textContent = 'This postal code is not serviceable. Choose an active delivery area before saving.'; return; }
    if (subtotal < rules.minimum_paise) { summary.textContent = `Minimum items subtotal for ${rules.name}: ${format(rules.minimum_paise)}.`; return; }
    const delivery = rules.free_above_paise !== null && subtotal >= rules.free_above_paise ? 0 : rules.fee_paise;
    summary.textContent = `Items ${format(subtotal)} · Savings ${format(discount)} · Delivery ${format(delivery)} · Total ${format(subtotal - discount + delivery)}`;
  }
  form.addEventListener('input', preview);
  form.addEventListener('change', preview);
  preview();
})();
