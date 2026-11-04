'use client';

import Link from 'next/link';
import ProductImage from './product-image';
import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { totals, rupees } from '../lib/purchase.mjs';
import { customerApi } from '../lib/customer';
import { fetchJson } from '../lib/http.mjs';
import { consumeCheckout, getCartVendor } from '../lib/cart-storage.mjs';
import { readCheckoutAttempt, saveCheckoutAttempt } from '../lib/checkout-recovery.mjs';
import OrderAftercare from './order-aftercare';
import CustomerSupport from './customer-support';
import DeliveryPin from './delivery-pin';

const steps = ['Order received', 'Picking your essentials', 'Out for delivery', 'At your doorstep'];

function EmptyBag() {
  return <div className="purchase-empty"><span aria-hidden="true">♡</span><h1>A little room for good things.</h1><p>Your bag is empty. Pick a few everyday favourites to get started.</p><Link className="primary" href="/category/all">Explore essentials →</Link></div>;
}

function Bill({ bill, children, deliveryKnown = true, pricing }) {
  return <aside className="bill-panel"><p className="eyebrow">THE LITTLE DETAILS</p><h2>Order summary</h2>{['per_km','locality_per_km'].includes(pricing?.mode) && <p className="bill-note"><b>{(pricing.distance_meters / 1000).toFixed(3)} km × {rupees(pricing.rate_paise_per_km)}/km</b><br />{pricing?.mode==='locality_per_km'?'Admin-set distance from the hub to your selected locality.':'Road distance from our fixed delivery hub to your pin.'}</p>}<dl className="bill-lines"><div><dt>Items subtotal</dt><dd>{rupees(bill.subtotal)}</dd></div><div><dt>Delivery fee</dt><dd>{deliveryKnown ? (bill.delivery ? rupees(bill.delivery) : 'Free') : 'At checkout'}</dd></div>{bill.discount > 0 && <div className="saving"><dt>Coupon savings</dt><dd>−{rupees(bill.discount)}</dd></div>}<div className="bill-total"><dt>{deliveryKnown ? 'Total' : 'Items total'}</dt><dd>{rupees(bill.total)}</dd></div></dl><p className="bill-note">Prices include applicable taxes. Delivery charges are confirmed for your selected delivery location before you place the order.</p>{children}</aside>;
}

export function PurchaseFlow({ view, orderId, products, cart, setCart, ready, location, Quantity, vendorId = null }) {
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
  const [pendingCheckout, setPendingCheckout] = useState(null);
  const [recoveryError, setRecoveryError] = useState('');
  const pendingKey = `dropbell-pending-checkout:${(!account?.isDemo && (account?.email || account?.phone)) || 'guest'}`;
  useEffect(() => {
    if (!authLoaded || view !== 'checkout') return;
    try {
      setPendingCheckout(readCheckoutAttempt(sessionStorage, pendingKey));
    } catch { setRecoveryError('Could not read checkout recovery data. Check your orders before placing another order.'); }
  }, [authLoaded, pendingKey, view]);
  useEffect(() => {
    customerApi('me').then(({ data }) => {
      setAccount(data); setSavedAddresses(data.addresses);
      const preferred = data.addresses.find(item => item.isDefault) || data.addresses[0];
      if (preferred) { setAddress({ ...preferred }); setSelectedAddress(preferred.id); }
      else setAddress(previous => ({ ...previous, name: data.name || '', phone: data.phone || '' }));
    }).catch(error => { if (error.status !== 401) setError(error.message); }).finally(() => setAuthLoaded(true));
    try { const value = sessionStorage.getItem('dropbell-coupon') || ''; if (/^[A-Z0-9_-]{3,30}$/.test(value)) { setCoupon(value); setCode(value); } } catch {}
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
  const baseEstimate = totals(products, cart);
  const [couponCheck, setCouponCheck] = useState(null);
  const couponKey = `${coupon}:${baseEstimate.subtotal}`;
  const couponDiscount = couponCheck?.key === couponKey ? couponCheck.discount : 0;
  const estimate = { ...baseEstimate, discount: couponDiscount, total: baseEstimate.subtotal - couponDiscount + baseEstimate.delivery, eligible: !coupon || couponCheck?.key === couponKey };
  useEffect(() => {
    if (!coupon || !baseEstimate.subtotal) return;
    const controller = new AbortController();
    fetchJson('/api/promotions', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ code: coupon, subtotal: baseEstimate.subtotal }), signal: controller.signal })
      .then(({ data }) => { setCouponCheck({ key: couponKey, discount: data.discount }); setCouponMessage(`${data.code} applied. Discount is confirmed again at checkout.`); })
      .catch(error => { if (!controller.signal.aborted) { setCouponCheck(null); setCouponMessage(error.message); } });
    return () => controller.abort();
  }, [coupon, couponKey, baseEstimate.subtotal]);
  const [quote, setQuote] = useState(null);
  const [quoteError, setQuoteError] = useState(null);
  const [quoteVersion, setQuoteVersion] = useState(0);
  const quotePayload = { vendor_id: vendorId, items: bag.map(item => ({ id: item.id, quantity: cart[item.id] })), sample_checkout: hasSamples, coupon: coupon || null, postal_code: address.pin, address };
  const quoteKey = JSON.stringify(quotePayload);
  const validQuote = quote?.key === quoteKey ? quote.data : null;
  const reviewItems = bag.map(item => ({ ...item, price: validQuote ? (validQuote.items.find(line => line.id === item.id)?.unit_price_paise ?? item.price * 100) / 100 : item.price }));
  const currentQuoteError = quoteError?.key === quoteKey ? quoteError.message : '';
  const bill = validQuote ? { ...validQuote.bill, eligible: estimate.eligible } : { ...estimate, delivery: 0, total: estimate.subtotal - estimate.discount };
  useEffect(() => {
    if (pendingCheckout || recoveryError || !['cart', 'checkout'].includes(view) || (!account && !hasSamples) || !ready || !bag.length || !/^[1-9]\d{5}$/.test(address.pin)) return;
    const controller = new AbortController();
    const timer = setTimeout(() => {
      customerApi(account ? 'quote' : 'sample-quote', { method: 'POST', body: JSON.parse(quoteKey), signal: controller.signal })
        .then(({ data }) => { setQuote({ key: quoteKey, data }); setQuoteError(null); })
        .catch(error => { if (!controller.signal.aborted) { setQuote(null); setQuoteError({ key: quoteKey, message: error.message }); } });
    }, 300);
    return () => { clearTimeout(timer); controller.abort(); };
  }, [view, account, ready, quoteKey, quoteVersion, hasSamples, pendingCheckout, recoveryError]);

  const applyCoupon = event => {
    event.preventDefault();
    const value = code.trim().toUpperCase();
    if (!/^[A-Z0-9_-]{3,30}$/.test(value)) { setCouponMessage('Enter a valid coupon code.'); return; }
    setCoupon(value); setCouponMessage('Checking your coupon…');
    try { sessionStorage.setItem('dropbell-coupon', value); } catch {}
  };
  const submitCheckout = async attempt => {
    if (placing.current) return;
    placing.current = true; setSubmitting(true); setError(''); setRecoveryError('');
    try {
      const { data } = await customerApi(attempt.endpoint, { method: 'POST', body: attempt.payload });
      const remaining = consumeCheckout(localStorage, attempt.payload.request_key, attempt.payload.items, attempt.payload.vendor_id ?? null);
      setCart(remaining);
      sessionStorage.removeItem(pendingKey);
      sessionStorage.removeItem('dropbell-coupon');
      setPendingCheckout(null);
      router.push(`/order/${data.id}`);
    } catch (error) {
      setError(error.status !== undefined ? error.message : 'Your checkout may be confirmed, but this browser could not save the confirmation. Retry the saved checkout or check your orders.');
      if (error.status === 422) {
        try { sessionStorage.removeItem(pendingKey); setPendingCheckout(null); }
        catch { setRecoveryError('Unable to update checkout recovery data. Please check your orders.'); }
        setQuote(null); setQuoteVersion(value => value + 1);
      }
    } finally { placing.current = false; setSubmitting(false); }
  };
  const placeOrder = async event => {
    event.preventDefault();
    if (placing.current || pendingCheckout || recoveryError || !bag.length || !validQuote) return;
    try {
      if (getCartVendor(localStorage) !== vendorId) { setError('Your shop selection changed in another tab. Reload checkout and review your bag before placing the order.'); return; }
    } catch { setRecoveryError('Could not read your saved shop selection. Reload and review your bag.'); return; }
    const clean = Object.fromEntries(Object.entries(address).map(([key, value]) => [key, typeof value === 'string' ? value.trim() : value]));
    let attempt = { endpoint: account ? 'orders' : 'sample-orders', payload: { vendor_id: vendorId, sample_checkout: hasSamples, request_key: crypto.randomUUID(), items: bag.map(item => ({ id: item.id, quantity: cart[item.id] })), address: clean, delivery, payment, coupon: coupon || null, expected_total: bill.total } };
    try {
      attempt = saveCheckoutAttempt(sessionStorage, pendingKey, attempt);
      setPendingCheckout(attempt);
    } catch {
      setRecoveryError('Your browser could not save checkout recovery data. Enable browser storage and reload before placing your order.');
      return;
    }
    await submitCheckout(attempt);
  };

  if (!authLoaded || (view === 'order' && !loadedOrder)) return <div className="purchase-empty" role="status">Getting your essentials ready…</div>;

  if (!['cart', 'checkout'].includes(view) && !account) return <div className="purchase-empty"><h1>Sign in to continue.</h1><p>{error || 'Keep your addresses and orders together in your Dropbell account.'}</p><Link className="primary" href="/login">Sign in →</Link></div>;
  if (view === 'order') {
    if (!order) return <div className="purchase-empty"><h1>Order unavailable.</h1><p>{error || 'Check your order history for available orders.'}</p><Link className="primary" href="/category/all">Browse essentials →</Link></div>;
    return <div className="purchase-page"><div className="breadcrumbs"><Link href="/">Home</Link><span>/</span><span>Your order</span></div><div className="confirmation-banner"><span className="confirmation-check">✓</span><p className="eyebrow">A HAPPY LITTLE DROP</p><h1>{order.isSample && order.status !== 'cancelled' ? 'Your test COD order is confirmed.' : order.status === 'cancelled' ? 'Your order was cancelled.' : order.stage === 3 ? 'Your delivery is complete.' : 'Your order is confirmed.'}</h1><p>{order.isSample ? 'Test Cash on Delivery order sent to admin. No payment or real delivery is required.' : 'Pay on delivery. Your latest order status appears below.'}</p><small>Order reference: {order.id}</small></div><div className="purchase-layout"><div><CustomerSupport orderReference={order.id}/><OrderAftercare order={order} onRefresh={refreshOrder} /><section className="purchase-panel"><div className="panel-title"><h2>Your doorstep journey</h2><span className="demo-pill">ORDER STATUS</span></div><ol className="tracking-steps">{steps.map((step, index) => <li key={step} className={order.status !== 'cancelled' && index <= order.stage ? 'complete' : ''} aria-current={index === order.stage ? 'step' : undefined}><span>{index <= order.stage ? '✓' : index + 1}</span><div><b>{step}</b>{index === order.stage && <small>Current status</small>}</div></li>)}</ol><button className="secondary" onClick={refreshOrder}>Refresh status</button><p className="bill-note">{order.status === 'cancelled' ? 'This order has been cancelled.' : 'Status updates automatically every 15 seconds.'}</p>{error && <p className="form-error" role="alert">{error}</p>}</section><section className="purchase-panel"><h2>Your delivery details</h2><p><b>{order.address.name}</b><br />{order.address.street}<br />{order.address.city} — {order.address.pin}<br />{order.address.phone}</p><p>{order.delivery}<br />{order.payment}</p></section><section className="purchase-panel"><h2>Your little haul</h2>{order.items.map(item => <div className="review-item" key={item.id}><span>{item.name}<small>{item.qty} × {item.quantity}</small></span><b>{rupees(item.price * item.quantity * 100)}</b></div>)}</section></div><Bill bill={order.bill} pricing={order.deliveryPricing}><Link className="primary" href="/category/all">Keep exploring →</Link><Link className="continue-shopping" href="/account/orders">View my orders</Link></Bill></div></div>;
  }
  if (view === 'checkout' && (pendingCheckout || recoveryError)) return <div className="purchase-empty"><h1>Let’s confirm your checkout.</h1><p>A checkout was saved on this tab. Retry the saved checkout to retrieve its confirmation or finish it safely with the same order reference.</p><p>Saved total: {pendingCheckout ? rupees(pendingCheckout.payload.expected_total) : 'Unavailable'}</p>{(error || recoveryError) && <p role="alert">{error || recoveryError}</p>}{pendingCheckout && <button className="primary" disabled={submitting} onClick={() => submitCheckout(pendingCheckout)}>{submitting ? 'Confirming checkout…' : 'Retry saved checkout'}</button>}<p><Link href="/account/orders">Check my orders</Link> · <Link href="/account/support">Contact support</Link></p></div>;
  if (!ready) return <div className="purchase-empty" role="status">Loading your bag…</div>;
  if (!bag.length) return <EmptyBag />;

  return <div className="purchase-page"><div className="breadcrumbs"><Link href="/">Home</Link><span>/</span><Link href="/cart">Your bag</Link>{view === 'checkout' && <><span>/</span><span>Checkout</span></>}</div><div className="purchase-heading"><p className="eyebrow">{view === 'cart' ? 'YOUR LITTLE HAUL' : 'ONE LAST LITTLE STEP'}</p><h1>{view === 'cart' ? 'Good things in your bag.' : 'Let’s find your doorstep.'}</h1><p>{view === 'cart' ? `${count} ${count === 1 ? 'item' : 'items'} picked for your everyday.` : 'Review your details and place your order.'}</p></div><div className="purchase-layout"><div>{view === 'cart' ? <><section className="purchase-panel"><div className="panel-title"><h2>Your essentials</h2><Link href="/category/all" className="text-button">Add more ↗</Link></div>{bag.map(product => <div className="bag-item" key={product.id}><Link className="bag-art" href={`/product/${product.id}`}><ProductImage product={product} /></Link><div className="bag-item-copy"><Link href={`/product/${product.id}`}>{product.name}</Link><small>{product.qty} · ₹{product.price} each</small><button onClick={() => setCart(previous => { const next = { ...previous }; delete next[product.id]; return next; })}>Remove</button></div><Quantity product={product} /><b className="bag-item-total">{rupees(product.price * cart[product.id] * 100)}</b></div>)}</section><section className="purchase-panel coupon-panel"><h2>A little extra saving</h2><p>Enter a coupon from our current offers. Eligibility and savings are checked automatically.</p><form onSubmit={applyCoupon}><label className="sr-only" htmlFor="coupon">Coupon code</label><input id="coupon" value={code} onChange={event => setCode(event.target.value)} placeholder="Enter coupon code" maxLength={30} /><button className="secondary" type="submit">Apply</button></form>{coupon && <button className="text-button" onClick={() => { setCoupon(''); setCode(''); setCouponMessage('Coupon removed.'); try { sessionStorage.removeItem('dropbell-coupon'); } catch {} }}>Remove {coupon} ×</button>}{couponMessage && <p role="status">{couponMessage}</p>}{coupon && !bill.eligible && <p role="status">This coupon has not been validated for the current bag. Review the message above.</p>}</section></> : <form id="checkout-form" onSubmit={placeOrder}><section className="purchase-panel"><div className="panel-title"><h2>1. Delivery address</h2></div><p className="bill-note">Choose a saved address or enter your delivery details.</p>{!account && !hasSamples && <p className="bill-note"><Link href="/login">Sign in</Link> to confirm delivery availability and place an order.</p>}<div className="checkout-saved"><label>Use a saved address<select value={selectedAddress} onChange={event => { const id = event.target.value; setSelectedAddress(id); const item = savedAddresses.find(value => value.id === id); setAddress(item ? { ...item } : { name: '', phone: '', street: '', city: '', pin: '' }); }}><option value="">Enter a new address</option>{savedAddresses.map(item => <option key={item.id} value={item.id}>{item.label || 'Home'} — {item.street}</option>)}</select></label><Link className="text-button" href="/account/addresses">Manage saved addresses ↗</Link></div>{location && <p className="area-hint">Your selected area: {location}</p>}<div className="address-fields">{[['name', 'Full name', 'text', 'Full name'], ['phone', 'Mobile number', 'tel', '10-digit Indian mobile number'], ['street', 'Flat, building & street', 'text', 'e.g. Flat 12, Garden Apartments']].map(([key, label, type, placeholder]) => <label key={key} className={key === 'street' ? 'wide' : ''}>{label}<input required id={`address-${key}`} type={type} value={address[key]} onChange={event => { setSelectedAddress(''); setAddress(previous => ({ ...previous, [key]: event.target.value, ...(false ? { latitude: null, longitude: null } : {}) })); }} placeholder={placeholder} maxLength={key === 'phone' ? 10 : key === 'pin' ? 6 : 120} pattern={key === 'phone' ? '[6-9][0-9]{9}' : key === 'pin' ? '[1-9][0-9]{5}' : undefined} inputMode={key === 'phone' || key === 'pin' ? 'numeric' : undefined} autoComplete="off" /></label>)}</div><DeliveryPin value={address} disabled={submitting} onChange={point => setAddress(previous => ({ ...previous, ...point }))} /></section><section className="purchase-panel"><h2>2. Payment preference</h2><fieldset className="choice-group"><legend className="sr-only">Payment preference</legend>{[['cod', 'Pay on delivery', hasSamples ? 'Test order only. No money will be collected.' : 'Pay when your order arrives.']].map(([value, label, note]) => <label key={value}><input type="radio" name="payment" value={value} checked={payment === value} onChange={() => setPayment(value)} /><span><b>{label}</b><small>{note}</small></span></label>)}</fieldset></section><section className="purchase-panel"><div className="panel-title"><h2>3. Review your bag</h2><Link className="text-button" href="/cart">Edit bag</Link></div>{reviewItems.map(item => <div className="review-item" key={item.id}><span>{item.name}<small>{item.qty} × {cart[item.id]}</small></span><b>{rupees(item.price * cart[item.id] * 100)}</b></div>)}</section></form>}</div><Bill bill={bill} deliveryKnown={!!validQuote} pricing={validQuote?.rules}>{view === 'cart' ? <><p className="bill-note">{address.street ? `Delivery to ${address.street}, ${address.pin}.` : 'Choose your delivery address at checkout to calculate charges.'}</p>{currentQuoteError && <p className="form-error" role="alert">{currentQuoteError}</p>}<Link className="primary" href="/checkout">Continue to checkout →</Link><Link className="continue-shopping" href="/category/all">Keep shopping</Link></> : <><button className="primary" type="submit" form="checkout-form" disabled={submitting || !validQuote}>{submitting ? 'Placing order…' : validQuote ? `${hasSamples ? 'Place test COD order' : 'Place COD order'} · ${rupees(bill.total)}` : 'Confirm delivery details'}</button>{validQuote && <p className="bill-note">Delivering to {validQuote.rules.name} · {address.pin}. {validQuote.rules.mode !== 'per_km' && <>Minimum items subtotal: {rupees(validQuote.rules.minimum_paise)}.</>}{validQuote.rules.free_above_paise !== null && <> Free delivery from {rupees(validQuote.rules.free_above_paise)}.</>}</p>}{hasSamples && <p className="form-error" role="status">Test Cash on Delivery: this creates a labelled test order in admin. No money will be collected and no real delivery will be scheduled.</p>}{!validQuote && !currentQuoteError && <p className="bill-note" role="status">{/^[1-9]\d{5}$/.test(address.pin) ? 'Checking delivery availability and your total…' : 'Select State, City and Locality to check delivery availability.'}</p>}{currentQuoteError && <><p className="form-error" role="alert">{currentQuoteError}</p><button className="text-button" type="button" onClick={() => { setQuoteError(null); setQuoteVersion(value => value + 1); }}>Check delivery again</button></>}{error && <p className="form-error" role="alert">{error}</p>}</>}<div className="purchase-demo-note">{hasSamples ? 'Sample products use test Cash on Delivery. Your test order will appear in admin; no money is collected.' : 'Your order is confirmed after availability and price checks.'}</div></Bill></div></div>;
}
