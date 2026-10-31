const key = 'dropbell-cart';

function read(storage) {
  const saved = JSON.parse(storage.getItem(key) || '{}');
  const cart = {};
  if (saved && typeof saved === 'object' && !Array.isArray(saved)) {
    for (const [id, quantity] of Object.entries(saved)) {
      if (/^[1-9]\d*$/.test(id) && Number.isInteger(quantity) && quantity > 0 && quantity <= 99) cart[id] = quantity;
    }
  }
  const consumed = Array.isArray(saved?.__consumed) ? saved.__consumed.filter(value => typeof value === 'string').slice(-20) : [];
  const vendorId = Number.isSafeInteger(saved?.__vendor_id) && saved.__vendor_id > 0 ? saved.__vendor_id : null;
  return { cart, consumed, vendorId };
}

export function saveCart(storage, cart) {
  const { consumed, vendorId } = read(storage);
  storage.setItem(key, JSON.stringify({ ...cart, __consumed: consumed, __vendor_id: vendorId }));
}

export function consumeCheckout(storage, requestKey, purchased, checkoutVendorId = null) {
  const { cart, consumed, vendorId } = read(storage);
  if (consumed.includes(requestKey)) return cart;
  for (const item of vendorId === checkoutVendorId ? purchased : []) {
    const remaining = (cart[item.id] || 0) - item.quantity;
    if (remaining > 0) cart[item.id] = remaining;
    else delete cart[item.id];
  }
  // Cart cleanup and its receipt are one write, so recovery can safely repeat it.
  storage.setItem(key, JSON.stringify({ ...cart, __consumed: [...consumed, requestKey].slice(-20), __vendor_id: vendorId }));
  return cart;
}

export function getCartVendor(storage) {
  return read(storage).vendorId;
}

export function switchCartVendor(storage, vendorId) {
  if (vendorId !== null && (!Number.isSafeInteger(vendorId) || vendorId <= 0)) throw new Error('Invalid shop');
  const { consumed } = read(storage);
  storage.setItem(key, JSON.stringify({ __consumed: consumed, __vendor_id: vendorId }));
}

export function readCart(storage) {
  return read(storage).cart;
}
