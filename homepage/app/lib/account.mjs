export const validPhone = phone => /^[6-9]\d{9}$/.test(phone);
export const validAddress = address => Boolean(address?.name?.trim() && address?.street?.trim() && address?.city?.trim() && validPhone(address?.phone || '') && /^[1-9]\d{5}$/.test(address?.pin || ''));
export function readAccount(storage) {
  const phone = storage.getItem('dropbell-user');
  if (!validPhone(phone || '')) return null;
  try {
    const saved = JSON.parse(storage.getItem(`dropbell-account-${phone}`) || '{}');
    const addresses = Array.isArray(saved.addresses) ? saved.addresses.filter(item => typeof item.id === 'string' && validAddress(item)) : [];
    return { phone, name: typeof saved.name === 'string' ? saved.name : '', addresses };
  } catch { return { phone, name: '', addresses: [] }; }
}
export function saveAccount(storage, account) {
  if (!validPhone(account.phone)) throw new Error('Invalid mobile number');
  storage.setItem(`dropbell-account-${account.phone}`, JSON.stringify(account));
}
export function readOrders(storage, phone) {
  const orders = [];
  for (let i = 0; i < storage.length; i++) {
    const key = storage.key(i);
    if (!key?.startsWith('dropbell-order-')) continue;
    try {
      const order = JSON.parse(storage.getItem(key));
      if (order?.id && Array.isArray(order.items) && order.items.length && order.bill && Number.isFinite(order.bill.total) && order.address && Number.isInteger(order.stage) && order.stage >= 0 && order.stage <= 3 && order.ownerPhone === phone) orders.push(order);
    } catch {}
  }
  return orders.sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt));
}
export function reorderCart(products, cart, items) {
  const next = { ...cart };
  for (const item of items) if (products.some(product => product.id === item.id) && Number.isInteger(item.quantity) && item.quantity > 0) next[item.id] = Math.min(99, products.find(product => product.id === item.id)?.stock ?? 99, (next[item.id] || 0) + item.quantity);
  return next;
}
