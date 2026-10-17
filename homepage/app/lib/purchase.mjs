export function totals(products, cart, coupon = '') {
  const subtotal = products.reduce((sum, product) => sum + Math.round(product.price * 100) * (cart[product.id] || 0), 0);
  const eligible = coupon === 'DROP10' && subtotal >= 10000;
  const discount = eligible ? Math.min(5000, Math.round(subtotal * .1)) : 0;
  const delivery = subtotal === 0 || subtotal >= 30000 ? 0 : 2500;
  return { subtotal, discount, delivery, total: subtotal - discount + delivery, eligible };
}
export const rupees = amount => new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: amount % 100 === 0 ? 0 : 2 }).format(amount / 100);
