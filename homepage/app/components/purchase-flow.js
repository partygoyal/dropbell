'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { totals, rupees } from '../lib/purchase.mjs';
import { customerApi } from '../lib/customer';
import OrderAftercare from './order-aftercare';

const steps = ['Order received', 'Picking your essentials', 'Out for delivery', 'At your doorstep'];

function EmptyBag() {
  return <div className="purchase-empty"><span aria-hidden="true">♡</span><h1>A little room for good things.</h1><p>Your bag is empty. Pick a few everyday favourites to get started.</p><Link className="primary" href="/category/all">Explore essentials →</Link></div>;
}

function Bill({ bill, children, deliveryKnown = true }) {
  return <aside className="bill-panel"><p className="eyebrow">THE LITTLE DETAILS</p><h2>Order summary</h2><dl className="bill-lines"><div><dt>Items subtotal</dt><dd>{rupees(bill.subtotal)}</dd></div><div><dt>Delivery fee</dt><dd>{deliveryKnown ? (bill.delivery ? rupees(bill.delivery) : 'Free') : 'At checkout'}</dd></div>{bill.discount > 0 && <div className="saving"><dt>DROP10 savings</dt><dd>−{rupees(bill.discount)}</dd></div>}<div className="bill-total"><dt>{deliveryKnown ? 'Total' : 'Items total'}</dt><dd>{rupees(bill.total)}</dd></div></dl><p className="bill-note">Prices include applicable taxes. Delivery fees and minimum order values depend on your postal code.</p>{children}</aside>;
}

export function PurchaseFlow({ view, orderId, products, cart, setCart, ready, location, Quantity }) {
  const router = useRouter();
  const [coupon, setCoupon] = useState('');
  const [code, setCode] = useState('');
  const [couponMessage, setCouponMessage] = useState('');
  const [error, setError] = useState('');
  const [order, setOrder] = useState(null);
  const [loadedOrder, setLoadedOrder] = useState(false);
  const delivery = 'standard';
  const [payment, setPayment] = useState('cod');
  const [submitting, setSubmitting] = useState(false);
  const placing = useRef(false);
  const [savedAddresses, setSavedAddresses] = useState([]);
  const [selectedAddress, setSelectedAddress] = useState('');
  const [address, setAddress] = useState({ name: '', phone: '', street: '', city: '', pin: '' });
  const [account, setAccount] = useState(null);
  const [authLoaded, setAuthLoaded] = useState(false);
  const requestKey = useRef(null);
  useEffect(() => {
    customerApi('me').then(({ data }) => {
      setAccount(data); setSavedAddresses(data.addresses);
      const preferred = data.addresses.find(item => item.isDefault) || data.addresses[0];
      if (preferred) { setAddress({ name: preferred.name, phone: preferred.phone, street: preferred.street, city: preferred.city, pin: preferred.pin }); setSelectedAddress(preferred.id); }
      else setAddress(previous => ({ ...previous, name: data.name || '', phone: data.phone || '' }));
    }).catch(error => { if (error.status !== 401) setError(error.message); }).finally(() => setAuthLoaded(true));
    try { const value = sessionStorage.getItem('dropbell-coupon') || ''; if (value === 'DROP10') { setCoupon(value); setCode(value); } } catch {}
  }, []);
  const refreshOrder = () => customerApi(`orders/${orderId}`).then(({ data }) => { setOrder(data); setError(''); }).catch(error => setError(error.message)).finally(() => setLoadedOrder(true));
  useEffect(() => {
    if (view !== 'order') return;
    refreshOrder();
    const timer = setInterval(refreshOrder, 15000);
    return () => clearInterval(timer);
  }, [view, orderId]);
  const bag = products.filter(product => cart[product.id]);
  const hasSamples = bag.some(product => product.sample);
  const count = Object.values(cart).reduce((sum, qty) => sum + qty, 0);
  const estimate = totals(products, cart, coupon);
  const [quote, setQuote] = useState(null);
  const [quoteError, setQuoteError] = useState(null);
  const [quoteVersion, setQuoteVersion] = useState(0);
  const quotePayload = { items: bag.map(item => ({ id: item.id, quantity: cart[item.id] })), sample_checkout: hasSamples, coupon: estimate.eligible ? coupon || null : null, postal_code: address.pin };
  const quoteKey = JSON.stringify(quotePayload);
  const validQuote = quote?.key === quoteKey ? quote.data : null;
  const reviewItems = bag.map(item => ({ ...item, price: validQuote ? (validQuote.items.find(line => line.id === item.id)?.unit_price_paise ?? item.price * 100) / 100 : item.price }));
  const currentQuoteError = quoteError?.key === quoteKey ? quoteError.message : '';
  const bill = validQuote ? { ...validQuote.bill, eligible: estimate.eligible } : { ...estimate, delivery: 0, total: estimate.subtotal - estimate.discount };
  useEffect(() => {
    if (view !== 'checkout' || (!account && !hasSamples) || !ready || !bag.length || !/^[1-9]\d{5}$/.test(address.pin)) return;
    const controller = new AbortController();
    const timer = setTimeout(() => {
      customerApi(account ? 'quote' : 'sample-quote', { method: 'POST', body: JSON.parse(quoteKey), signal: controller.signal })
        .then(({ data }) => { setQuote({ key: quoteKey, data }); setQuoteError(null); })
        .catch(error => { if (!controller.signal.aborted) { setQuote(null); setQuoteError({ key: quoteKey, message: error.message }); } });
    }, 300);
    return () => { clearTimeout(timer); controller.abort(); };
  }, [view, account, ready, quoteKey, quoteVersion, hasSamples]);

  const applyCoupon = event => {
    event.preventDefault();
    const value = code.trim().toUpperCase();
    if (value !== 'DROP10') { setCouponMessage('Try DROP10 for 10% off, up to ₹50, on an items subtotal of ₹100 or more.'); return; }
    if (!totals(products, cart, value).eligible) { setCouponMessage('Add items worth ₹100 or more to use DROP10.'); return; }
    setCoupon(value); setCouponMessage('DROP10 applied. A little saving for your day.');
    try { sessionStorage.setItem('dropbell-coupon', value); } catch {}
  };
  const placeOrder = async event => {
    event.preventDefault();
    if (placing.current || !bag.length || !validQuote) return;
    const clean = Object.fromEntries(Object.entries(address).map(([key, value]) => [key, value.trim()]));
    placing.current = true; setSubmitting(true); setError('');
    requestKey.current ||= crypto.randomUUID();
    try {
      const { data } = await customerApi(account ? 'orders' : 'sample-orders', { method: 'POST', body: { sample_checkout: hasSamples, request_key: requestKey.current, items: bag.map(item => ({ id: item.id, quantity: cart[item.id] })), address: clean, delivery, payment, coupon: bill.eligible ? coupon || null : null, expected_total: bill.total } });
      try { localStorage.setItem('dropbell-cart', '{}'); sessionStorage.removeItem('dropbell-coupon'); } catch {}
      setCart({}); router.push(`/order/${data.id}`);
    } catch (error) { setError(error.message); placing.current = false; setSubmitting(false); if (error.status === 422) { setQuote(null); setQuoteVersion(value => value + 1); } }
  };

  if (!ready || !authLoaded || (view === 'order' && !loadedOrder)) return <div className="purchase-empty" role="status">Getting your essentials ready…</div>;

  if (!['cart', 'checkout'].includes(view) && !account) return <div className="purchase-empty"><h1>Sign in to continue.</h1><p>{error || 'Keep your addresses and orders together in your Dropbell account.'}</p><Link className="primary" href="/login">Sign in →</Link></div>;
  if (view === 'order') {
    if (!order) return <div className="purchase-empty"><h1>Order unavailable.</h1><p>{error || 'Check your order history for available orders.'}</p><Link className="primary" href="/category/all">Browse essentials →</Link></div>;
    return <div className="purchase-page"><div className="breadcrumbs"><Link href="/">Home</Link><span>/</span><span>Your order</span></div><div className="confirmation-banner"><span className="confirmation-check">✓</span><p className="eyebrow">A HAPPY LITTLE DROP</p><h1>{order.isSample && order.status !== 'cancelled' ? 'Your test COD order is confirmed.' : order.status === 'cancelled' ? 'Your order was cancelled.' : order.stage === 3 ? 'Your delivery is complete.' : 'Your order is confirmed.'}</h1><p>{order.isSample ? 'Test Cash on Delivery order sent to admin. No payment or real delivery is required.' : 'Pay on delivery. Your latest order status appears below.'}</p><small>Order reference: {order.id}</small></div><div className="purchase-layout"><div><OrderAftercare order={order} onRefresh={refreshOrder} /><section className="purchase-panel"><div className="panel-title"><h2>Your doorstep journey</h2><span className="demo-pill">ORDER STATUS</span></div><ol className="tracking-steps">{steps.map((step, index) => <li key={step} className={order.status !== 'cancelled' && index <= order.stage ? 'complete' : ''} aria-current={index === order.stage ? 'step' : undefined}><span>{index <= order.stage ? '✓' : index + 1}</span><div><b>{step}</b>{index === order.stage && <small>Current status</small>}</div></li>)}</ol><button className="secondary" onClick={refreshOrder}>Refresh status</button><p className="bill-note">{order.status === 'cancelled' ? 'This order has been cancelled.' : 'Status updates automatically every 15 seconds.'}</p>{error && <p className="form-error" role="alert">{error}</p>}</section><section className="purchase-panel"><h2>Your delivery details</h2><p><b>{order.address.name}</b><br />{order.address.street}<br />{order.address.city} — {order.address.pin}<br />{order.address.phone}</p><p>{order.delivery}<br />{order.payment}</p></section><section className="purchase-panel"><h2>Your little haul</h2>{order.items.map(item => <div className="review-item" key={item.id}><span>{item.name}<small>{item.qty} × {item.quantity}</small></span><b>{rupees(item.price * item.quantity * 100)}</b></div>)}</section></div><Bill bill={order.bill}><Link className="primary" href="/category/all">Keep exploring →</Link><Link className="continue-shopping" href="/account/orders">View my orders</Link></Bill></div></div>;
  }
  if (!bag.length) return <EmptyBag />;

  return <div className="purchase-page"><div className="breadcrumbs"><Link href="/">Home</Link><span>/</span><Link href="/cart">Your bag</Link>{view === 'checkout' && <><span>/</span><span>Checkout</span></>}</div><div className="purchase-heading"><p className="eyebrow">{view === 'cart' ? 'YOUR LITTLE HAUL' : 'ONE LAST LITTLE STEP'}</p><h1>{view === 'cart' ? 'Good things in your bag.' : 'Let’s find your doorstep.'}</h1><p>{view === 'cart' ? `${count} ${count === 1 ? 'item' : 'items'} picked for your everyday.` : 'Review your details and place your order.'}</p></div><div className="purchase-layout"><div>{view === 'cart' ? <><section className="purchase-panel"><div className="panel-title"><h2>Your essentials</h2><Link href="/category/all" className="text-button">Add more ↗</Link></div>{bag.map(product => <div className="bag-item" key={product.id}><Link className="bag-art" href={`/product/${product.id}`}>{product.photo ? <img src={product.photo.startsWith('http') ? product.photo : `https://images.unsplash.com/${product.photo}?auto=format&fit=crop&w=180&q=80`} alt={product.name} /> : <span aria-label={product.name} role="img">{product.emoji}</span>}</Link><div className="bag-item-copy"><Link href={`/product/${product.id}`}>{product.name}</Link><small>{product.qty} · ₹{product.price} each</small><button onClick={() => setCart(previous => { const next = { ...previous }; delete next[product.id]; return next; })}>Remove</button></div><Quantity product={product} /><b className="bag-item-total">{rupees(product.price * cart[product.id] * 100)}</b></div>)}</section><section className="purchase-panel coupon-panel"><h2>A little extra saving</h2><p>Use DROP10 for 10% off, up to ₹50, on items worth ₹100 or more.</p><form onSubmit={applyCoupon}><label className="sr-only" htmlFor="coupon">Coupon code</label><input id="coupon" value={code} onChange={event => setCode(event.target.value)} placeholder="Enter coupon code" maxLength={30} /><button className="secondary" type="submit">Apply</button></form>{coupon && <button className="text-button" onClick={() => { setCoupon(''); setCode(''); setCouponMessage('Coupon removed.'); try { sessionStorage.removeItem('dropbell-coupon'); } catch {} }}>Remove DROP10 ×</button>}{couponMessage && <p role="status">{couponMessage}</p>}{coupon && !bill.eligible && <p role="status">DROP10 needs an items subtotal of ₹100. Discount is currently inactive.</p>}</section></> : <form id="checkout-form" onSubmit={placeOrder}><section className="purchase-panel"><div className="panel-title"><h2>1. Delivery address</h2></div><p className="bill-note">Choose a saved address or enter your delivery details.</p>{!account && !hasSamples && <p className="bill-note"><Link href="/login">Sign in</Link> to confirm delivery availability and place an order.</p>}<div className="checkout-saved"><label>Use a saved address<select value={selectedAddress} onChange={event => { const id = event.target.value; setSelectedAddress(id); const item = savedAddresses.find(value => value.id === id); setAddress(item ? { name: item.name, phone: item.phone, street: item.street, city: item.city, pin: item.pin } : { name: '', phone: '', street: '', city: '', pin: '' }); }}><option value="">Enter a new address</option>{savedAddresses.map(item => <option key={item.id} value={item.id}>{item.label || 'Home'} — {item.street}</option>)}</select></label><Link className="text-button" href="/account/addresses">Manage saved addresses ↗</Link></div>{location && <p className="area-hint">Your selected area: {location}</p>}<div className="address-fields">{[['name', 'Full name', 'text', 'Full name'], ['phone', 'Mobile number', 'tel', '10-digit Indian mobile number'], ['street', 'Flat, building & street', 'text', 'e.g. Flat 12, Garden Apartments'], ['city', 'City / locality', 'text', 'e.g. Bengaluru'], ['pin', 'Postal code', 'text', '6-digit postal code']].map(([key, label, type, placeholder]) => <label key={key} className={key === 'street' ? 'wide' : ''}>{label}<input required id={`address-${key}`} type={type} value={address[key]} onChange={event => { setSelectedAddress(''); setAddress(previous => ({ ...previous, [key]: event.target.value })); }} placeholder={placeholder} maxLength={key === 'phone' ? 10 : key === 'pin' ? 6 : 120} pattern={key === 'phone' ? '[6-9][0-9]{9}' : key === 'pin' ? '[1-9][0-9]{5}' : undefined} inputMode={key === 'phone' || key === 'pin' ? 'numeric' : undefined} autoComplete="off" /></label>)}</div></section><section className="purchase-panel"><h2>2. Payment preference</h2><fieldset className="choice-group"><legend className="sr-only">Payment preference</legend>{[['cod', 'Pay on delivery', hasSamples ? 'Test order only. No money will be collected.' : 'Pay when your order arrives.']].map(([value, label, note]) => <label key={value}><input type="radio" name="payment" value={value} checked={payment === value} onChange={() => setPayment(value)} /><span><b>{label}</b><small>{note}</small></span></label>)}</fieldset></section><section className="purchase-panel"><div className="panel-title"><h2>3. Review your bag</h2><Link className="text-button" href="/cart">Edit bag</Link></div>{reviewItems.map(item => <div className="review-item" key={item.id}><span>{item.name}<small>{item.qty} × {cart[item.id]}</small></span><b>{rupees(item.price * cart[item.id] * 100)}</b></div>)}</section></form>}</div><Bill bill={bill} deliveryKnown={!!validQuote}>{view === 'cart' ? <><Link className="primary" href="/checkout">Continue to checkout →</Link><Link className="continue-shopping" href="/category/all">Keep shopping</Link></> : <><button className="primary" type="submit" form="checkout-form" disabled={submitting || !validQuote}>{submitting ? 'Placing order…' : validQuote ? `${hasSamples ? 'Place test COD order' : 'Place COD order'} · ${rupees(bill.total)}` : 'Confirm delivery details'}</button>{validQuote && <p className="bill-note">Delivering to {validQuote.rules.name} · {address.pin}. Minimum items subtotal: {rupees(validQuote.rules.minimum_paise)}.{validQuote.rules.free_above_paise !== null && <> Free delivery from {rupees(validQuote.rules.free_above_paise)}.</>}</p>}{hasSamples && <p className="form-error" role="status">Test Cash on Delivery: this creates a labelled test order in admin. No money will be collected and no real delivery will be scheduled.</p>}{!validQuote && !currentQuoteError && <p className="bill-note" role="status">{/^[1-9]\d{5}$/.test(address.pin) ? 'Checking delivery availability and your total…' : 'Enter a 6-digit postal code to check delivery availability.'}</p>}{currentQuoteError && <><p className="form-error" role="alert">{currentQuoteError}</p><button className="text-button" type="button" onClick={() => { setQuoteError(null); setQuoteVersion(value => value + 1); }}>Check delivery again</button></>}{error && <p className="form-error" role="alert">{error}</p>}</>}<div className="purchase-demo-note">{hasSamples ? 'Sample products use test Cash on Delivery. Your test order will appear in admin; no money is collected.' : 'Your order is confirmed after availability and price checks.'}</div></Bill></div></div>;
}
