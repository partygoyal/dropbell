'use client';

import SiteIcon from './site-icon';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { validPhone, validAddress, reorderCart } from '../lib/account.mjs';
import { customerApi } from '../lib/customer';
import { rupees } from '../lib/purchase.mjs';
import AccountActivity from './account-activity';
import CustomerSupport from './customer-support';
import DeliveryPin from './delivery-pin';
import {FavouritesPanel} from './discovery-features';

const blankAddress = { label: 'Home', name: '', phone: '', street: '', city: '', pin: '' };
const accountLinks = [['account', '/account', 'My profile'], ['favourites','/account/favourites','Favourites'], ['addresses', '/account/addresses', 'Saved addresses'], ['orders', '/account/orders', 'My orders'], ['transactions', '/account/transactions', 'Transactions'], ['requests', '/account/requests', 'Returns & refunds'], ['support','/account/support','Customer Support']];
const orderStatuses = ['Order received', 'Picking essentials', 'Out for delivery', 'Delivered'];

export function AccountViews({ view, products, setCart, onReorder }) {
  const router = useRouter();
  const [loaded, setLoaded] = useState(false);
  const [account, setAccount] = useState(null);
  const [phone, setPhone] = useState('');
  const [otp, setOtp] = useState('');
  const [email,setEmail]=useState(''),[password,setPassword]=useState(''),[confirm,setConfirm]=useState('');
  const [registering,setRegistering]=useState(false);
  const [otpStep, setOtpStep] = useState(false);
  const [name, setName] = useState('');
  const [address, setAddress] = useState(null);
  const [orders, setOrders] = useState([]);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [pendingDelete, setPendingDelete] = useState(null);
  const [challenge, setChallenge] = useState('');
  const [localCode, setLocalCode] = useState('');
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        const [{ data: user }, { data: history }] = await Promise.all([customerApi('me'), customerApi('orders')]);
        if (alive) { setAccount(user); setName(user.name || ''); setOrders(history); }
      } catch (error) { if (alive && error.status !== 401) setError(error.message); }
      finally { if (alive) setLoaded(true); }
    })();
    return () => { alive = false; };
  }, [view]);
  const commit = async next => {
    try {
      const defaultAddress = next.addresses.find(item => item.isDefault && !account.addresses.find(old => old.id === item.id)?.isDefault);
      const result = defaultAddress ? await customerApi(`addresses/${defaultAddress.id}`, { method: 'PUT', body: defaultAddress }) : await customerApi('me', { method: 'PATCH', body: { name: next.name } });
      setAccount(result.data); window.dispatchEvent(new Event('dropbell-account-change')); setError(''); return true;
    } catch (error) { setError(error.message); return false; }
  };
  const logout = async () => {
    try { await customerApi('logout', { method: 'POST', body: {} }); window.dispatchEvent(new Event('dropbell-account-change')); setAccount(null); router.push('/login'); } catch (error) { setError(error.message); }
  };
  const submitLogin = async event => {
    event.preventDefault(); if(busy)return;setBusy(true);setError('');
    try { await customerApi(registering?'auth/register':'auth/login',{method:'POST',body:{email:email.trim(),password,...(registering?{name:name.trim(),phone,password_confirmation:confirm}:{})}});window.dispatchEvent(new Event('dropbell-account-change'));router.push('/'); }
    catch(e){setError(e.message);}finally{setBusy(false);}
  };
  const saveAddress = async event => {
    event.preventDefault(); if (busy) return; setBusy(true);
    const clean = Object.fromEntries(Object.entries(address).map(([key, value]) => [key, typeof value === 'string' ? value.trim() : value]));
    try {
      if (!validAddress(clean)) throw new Error('Complete the address with a valid Indian mobile number and postal code.');
      const result = await customerApi(clean.id ? `addresses/${clean.id}` : 'addresses', { method: clean.id ? 'PUT' : 'POST', body: clean });
      setAccount(result.data); setAddress(null); setMessage('Address saved.'); setError('');
    } catch (error) { setError(error.message); } finally { setBusy(false); }
  };
  const removeAddress = async id => {
    try { const result = await customerApi(`addresses/${id}`, { method: 'DELETE', body: {} }); setAccount(result.data); setPendingDelete(null); setMessage('Address removed.'); setError(''); } catch (error) { setError(error.message); }
  };

  if (view === 'help') return <div className="account-page"><div className="breadcrumbs"><Link href="/">Home</Link><span>/</span><span>Help & support</span></div><div className="account-heading"><p className="eyebrow">A LITTLE HELP GOES A LONG WAY</p><h1>Here for your everyday.</h1><p>Find your way around the Dropbell shopping experience.</p></div><div className="help-grid"><section className="purchase-panel"><h2>Your questions, answered.</h2>{[['How do I sign in?', 'Sign in with your email and password, or create a customer account.'], ['Where are my orders?', 'Orders placed while signed in appear in My orders and are stored in your account.'], ['How does delivery tracking work?', 'Your order page shows the status set by the Dropbell administrator. Refresh the page to see updates.'], ['Can I pay for an order?', 'Pay on delivery is available. Online payments are not connected yet.'], ['How do saved addresses work?', 'Add an address under My account, set a default, then select it during checkout. Saved addresses are stored in your account.']].map(([question, answer]) => <details className="faq-item" key={question}><summary>{question}</summary><p>{answer}</p></details>)}</section><aside className="help-card"><span aria-hidden="true"><SiteIcon name="heart" size={16} /></span><h2>Looking for an order?</h2><p>Open your order details to see the summary and latest delivery status.</p><Link className="primary" href="/account/orders">View my orders <SiteIcon name="arrow" size={16} /></Link><Link className="secondary" href="/account/support">Raise a concern</Link></aside></div></div>;
  if (!loaded) return <div className="purchase-empty" role="status">Getting your account ready…</div>;
  if (view === 'login') return <div className="login-layout"><div className="login-story"><p className="eyebrow">WELCOME TO DROPBELL</p><h1>Your everyday.<br />Delivered home.</h1><p>Shop in the delivery localities selected by Dropbell.</p></div><section className="login-card">{account?<><h2>You’re signed in.</h2><Link className="primary" href="/">Start shopping</Link><button onClick={logout}>Sign out</button></>:<><h2>{registering?'Create your account':'Welcome back'}</h2><form onSubmit={submitLogin}>{registering&&<><label>Full name<input required value={name} onChange={e=>setName(e.target.value)} maxLength={60}/></label><label>Mobile number<input required type="tel" pattern="[6-9][0-9]{9}" value={phone} onChange={e=>setPhone(e.target.value)}/></label></>}<label>Email<input required type="email" autoComplete="email" value={email} onChange={e=>setEmail(e.target.value)}/></label><label>Password<input required type="password" minLength={registering?8:1} autoComplete={registering?'new-password':'current-password'} value={password} onChange={e=>setPassword(e.target.value)}/></label>{registering&&<label>Confirm password<input required type="password" value={confirm} onChange={e=>setConfirm(e.target.value)}/></label>}{error&&<p role="alert" className="form-error">{error}</p>}<button className="primary" disabled={busy}>{busy?'Please wait…':registering?'Create account':'Sign in'}</button></form>{!registering&&<p><Link href="/forgot-password">Forgot password?</Link></p>}<button className="text-button" onClick={()=>{setRegistering(!registering);setError('');}}>{registering?'Already have an account? Sign in':'New customer? Create an account'}</button></>}</section></div>;
  if (!account) return <div className="purchase-empty"><span aria-hidden="true"><SiteIcon name="heart" size={16} /></span><h1>A little space for your everyday.</h1><p>Sign in to save addresses and see your orders.</p><Link className="primary" href="/login">Sign in <SiteIcon name="arrow" size={16} /></Link></div>;

  return <div className="account-page"><div className="breadcrumbs"><Link href="/">Home</Link><span>/</span><Link href="/account">My account</Link>{view !== 'account' && <><span>/</span><span>{view === 'addresses' ? 'Saved addresses' : view === 'transactions' ? 'Transactions' : view === 'requests' ? 'Returns & refunds' : view==='support'?'Customer Support':view==='favourites'?'Favourites':'My orders'}</span></>}</div><div className="account-heading"><p className="eyebrow">YOUR OWN LITTLE CORNER</p><h1>{view === 'favourites' ? 'Your favourites.' : view === 'addresses' ? 'Your happy doorsteps.' : view === 'orders' ? 'Your everyday, delivered.' : view === 'transactions' ? 'Your payments, together.' : view === 'requests' ? 'Here to make things right.' : `Hello${account.name ? `, ${account.name}` : ' there'}.`}</h1><p>{view === 'addresses' ? 'Save a doorstep. Make the next drop easier.' : view === 'orders' ? 'Find your past picks and keep an eye on your next drop.' : 'A few details for a little more ease.'}</p></div><div className="account-layout"><aside className="account-sidebar"><div className="profile-mini"><span>{(account.name || 'D').slice(0, 1).toUpperCase()}</span><b>{account.name || 'Dropbell customer'}</b><small>+91 {account.phone}</small></div>{accountLinks.map(([key, href, label]) => <Link key={key} className={key === view ? 'selected' : ''} aria-current={key === view ? 'page' : undefined} href={href}>{label}</Link>)}<Link href="/help">Help & FAQs</Link><button onClick={logout}>Sign out</button><p>Your Dropbell account</p></aside><div>{message && <p className="account-message" role="status">{message}</p>}{error && <p className="form-error" role="alert">{error}</p>}
    {view === 'account' && <><section className="purchase-panel"><h2>My profile</h2><form className="profile-form" onSubmit={async event => { event.preventDefault(); if (await commit({ ...account, name: name.trim() })) setMessage('Profile updated. Good to see you.'); }}><label>Full name<input value={name} onChange={event => setName(event.target.value)} required maxLength={60} placeholder="Your name" /></label><label>Mobile number<input value={`+91 ${account.phone}`} readOnly /></label><p className="bill-note">To use a different number, sign out and sign in with your other number.</p><button className="primary" type="submit" disabled={busy}>Save profile</button></form></section><div className="account-shortcuts"><Link href="/account/addresses"><span><SiteIcon name="home" size={16} /></span><h3>{account.addresses.length} saved {account.addresses.length === 1 ? 'address' : 'addresses'}</h3><p>Make your next drop easier <SiteIcon name="arrow-up-right" size={16} /></p></Link><Link href="/account/orders"><span><SiteIcon name="heart" size={16} /></span><h3>{orders.length} {orders.length === 1 ? 'order' : 'orders'}</h3><p>Revisit your everyday favourites <SiteIcon name="arrow-up-right" size={16} /></p></Link></div></>}
    {view === 'addresses' && <><div className="panel-title"><h2>Saved addresses</h2><button className="primary" onClick={() => { setAddress({ ...blankAddress, name: account.name, phone: account.phone }); setError(''); }}>+ Add address</button></div>{address && <section className="purchase-panel"><h2>{address.id ? 'Edit your doorstep' : 'A new doorstep'}</h2><form onSubmit={saveAddress}><div className="address-fields"><label>Address label<select value={address.label} onChange={event => setAddress(previous => ({ ...previous, label: event.target.value }))}><option>Home</option><option>Work</option><option>Other</option></select></label>{[['name', 'Full name'], ['phone', 'Mobile number'], ['street', 'Flat, building & street']].map(([key, label]) => <label key={key} className={key === 'street' ? 'wide' : ''}>{label}<input required value={address[key]} onChange={event => setAddress(previous => ({ ...previous, [key]: event.target.value, ...(false ? { latitude: null, longitude: null } : {}) }))} maxLength={key === 'phone' ? 10 : key === 'pin' ? 6 : 120} pattern={key === 'phone' ? '[6-9][0-9]{9}' : key === 'pin' ? '[1-9][0-9]{5}' : undefined} inputMode={key === 'phone' || key === 'pin' ? 'numeric' : undefined} autoComplete="off" /></label>)}</div><DeliveryPin value={address} disabled={busy} onChange={point => setAddress(previous => ({ ...previous, ...point }))} /><div className="account-actions"><button className="primary" type="submit" disabled={busy}>Save address</button><button type="button" className="text-button" onClick={() => setAddress(null)}>Cancel</button></div></form></section>}{!account.addresses.length && !address && <div className="account-empty"><span><SiteIcon name="home" size={16} /></span><h2>No doorsteps saved yet.</h2><p>Add your first address to use it at checkout.</p></div>}<div className="saved-addresses">{account.addresses.map(item => <article className="purchase-panel" key={item.id}><div className="panel-title"><h2>{item.label || 'Home'}</h2>{item.isDefault && <span className="demo-pill">DEFAULT</span>}</div><b>{item.name}</b><p>{item.street}<br />{item.city} — {item.pin}<br />+91 {item.phone}</p><div className="address-actions"><button onClick={() => { setAddress({ ...item }); setError(''); }}>Edit</button>{!item.isDefault && <button onClick={async () => { if (await commit({ ...account, addresses: account.addresses.map(value => ({ ...value, isDefault: value.id === item.id })) })) setMessage('Default address updated.'); }}>Make default</button>}<button onClick={() => setPendingDelete(item.id)}>Delete</button></div>{pendingDelete === item.id && <div className="delete-confirm"><p>Remove this saved address?</p><button className="secondary" onClick={() => removeAddress(item.id)}>Remove address</button><button className="text-button" onClick={() => setPendingDelete(null)}>Keep it</button></div>}</article>)}</div></>}
    {view==='support'&&<CustomerSupport/>}
    {['transactions', 'requests'].includes(view) && <AccountActivity view={view} />}
    {view === 'favourites' && <FavouritesPanel/>}{view === 'orders' && <>{!orders.length ? <div className="account-empty"><span><SiteIcon name="heart" size={16} /></span><h2>Your first little haul awaits.</h2><p>Orders placed while signed in will appear here. Your orders are securely saved to your account.</p><Link className="primary" href="/category/all">Find your favourites <SiteIcon name="arrow" size={16} /></Link></div> : orders.map(order => <article className="purchase-panel history-order" key={order.id}><div className="panel-title"><div><span className="demo-pill">{order.status === 'cancelled' ? 'Cancelled' : orderStatuses[order.stage]}</span><p>{new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeZone: 'Asia/Kolkata' }).format(new Date(order.createdAt))}</p></div><b>{rupees(order.bill.total)}</b></div><small className="order-ref">{order.id}</small><p>{order.items.map(item => `${item.name} × ${item.quantity}`).join(' · ')}</p><div className="account-actions"><Link className="secondary" href={`/order/${order.id}`}>Order details & tracking</Link><button className="primary" onClick={() => onReorder(order.id)}>Reorder <SiteIcon name="arrow" size={16} /></button></div></article>)}<p className="bill-note">Orders are stored in your account. Reorders use current prices and availability.</p></>}
  </div></div></div>;
}
