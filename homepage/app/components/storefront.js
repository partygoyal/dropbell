'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { CatalogView, ProductView } from './shopping-views';
import { PurchaseFlow } from './purchase-flow';
import { AccountViews } from './account-views';
import CartDrawer from './cart-drawer';
import { customerApi } from '../lib/customer';
import { categoryImageStyle } from '../lib/category-images';

const image = (id, width = 500) => id.startsWith('http') ? id : `https://images.unsplash.com/${id}?auto=format&fit=crop&w=${width}&q=80`;
const categoryName = { Fresh: 'Fruits & vegetables', Breakfast: 'Dairy & breakfast', Home: 'Home essentials', Snacks: 'Snacks & drinks' };
function Icon({ name, className = '' }) {
  return <svg className={`ui-icon ${className}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
    {name === 'pin' && <><path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z" /><circle cx="12" cy="10" r="2.5" /></>}
    {name === 'chevron' && <path d="m6 9 6 6 6-6" />}
    {name === 'search' && <><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 4.5 4.5" /></>}
    {name === 'arrow' && <><path d="M6 18 18 6M6 6h12v12" /></>}
  </svg>;
}

function CategoryNavigation({ departments, shortcuts, selected, onSelect }) {
  const menuRef = useRef(null);
  useEffect(() => {
    const dismiss = event => { if (!menuRef.current?.contains(event.target)) menuRef.current?.removeAttribute('open'); };
    const escape = event => { if (event.key === 'Escape' && menuRef.current?.open) { menuRef.current.removeAttribute('open'); menuRef.current.querySelector('summary')?.focus({ preventScroll: true }); } };
    document.addEventListener('pointerdown', dismiss);
    document.addEventListener('keydown', escape);
    return () => { document.removeEventListener('pointerdown', dismiss); document.removeEventListener('keydown', escape); };
  }, []);
  const select = value => { menuRef.current?.removeAttribute('open'); onSelect(value); };
  return <nav className="store-category-nav" aria-label="Product departments">
    <details className="category-menu" ref={menuRef}>
      <summary><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /></svg>Shop categories<Icon name="chevron" /></summary>
      <div className="category-menu-panel"><div className="category-menu-heading"><b>Shop your everyday</b><small>Browse every department</small></div><div className="category-menu-grid">{departments.map(([value, name]) => <button type="button" key={value} className={selected.toLowerCase() === value.toLowerCase() ? 'active' : ''} aria-pressed={selected.toLowerCase() === value.toLowerCase()} onClick={() => select(value)}>{name}</button>)}</div></div>
    </details>
    <div className="category-shortcuts">{shortcuts.map(([value, name]) => <button type="button" key={value} className={selected.toLowerCase() === value.toLowerCase() ? 'active' : ''} aria-pressed={selected.toLowerCase() === value.toLowerCase()} onClick={() => select(value)}>{name}</button>)}</div>
    <p className="category-nav-note">Made for your everyday ♡</p>
  </nav>;
}

function Logo() { return <img className="logo" src="/assets/logo.png" alt="Dropbell" />; }

function Modal({ children, label, className = '', onClose }) {
  const ref = useRef(null);
  useEffect(() => { const dialog = ref.current; dialog.showModal(); return () => dialog.close(); }, []);
  return <dialog ref={ref} className={className} aria-label={label} onCancel={onClose} onClick={event => {
    if (event.target !== event.currentTarget) return;
    const rect = event.currentTarget.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) onClose();
  }}><button className="close" onClick={onClose} aria-label="Close">×</button>{children}</dialog>;
}

export default function Storefront({ category = 'All', productId = null, view = 'home', orderId = null }) {
  const router = useRouter();
  const [products, setProducts] = useState([]);
  const [managedCategories, setManagedCategories] = useState([]);
  const [subCategories, setSubCategories] = useState([]);
  const departments = [['All', 'All essentials'], ...managedCategories.map(item => [item.name, categoryName[item.name] || item.name])];
  const featuredDepartments = [['All', 'All essentials'], ['Fruits & Vegetables', 'Fresh produce'], ['Dairy, Bread & Eggs', 'Dairy & eggs'], ['Snacks & Namkeen', 'Snacks']];
  const shortcuts = featuredDepartments.filter(([value]) => value === 'All' || managedCategories.some(item => item.name === value));
  const filterNames = shortcuts;
  const categories = managedCategories.map(item => [item.name, categoryName[item.name] || item.name]);
  const [catalogueReady, setCatalogueReady] = useState(false);
  const [catalogueError, setCatalogueError] = useState('');
  const loadCatalogue = async () => {
    try {
      let pin = ''; try { pin = localStorage.getItem('dropbell-area') || ''; } catch {}
      const query = /^[1-9]\d{5}$/.test(pin) ? `?postal_code=${pin}` : '';
      const response = await fetch(`/api/catalog${query}`, { cache: 'no-store' });
      if (!response.ok) throw new Error('The catalogue is unavailable. Check that Laravel is running, then retry.');
      const body = await response.json();
      const legacyReplacements = { Fresh: 'Fruits & Vegetables', Breakfast: 'Dairy, Bread & Eggs', Snacks: 'Snacks & Namkeen', Home: 'Cleaning & Household Essentials' };
      const catalogueCategories = body.categories || [];
      setManagedCategories(catalogueCategories.filter(item => !legacyReplacements[item.name] || !catalogueCategories.some(other => other.name === legacyReplacements[item.name]) || body.data.some(product => product.category === item.name)));
      setSubCategories(body.sub_categories || []);
      const icons = { Fresh: '🥬', Breakfast: '🥛', Snacks: '🍿', Home: '🧴' };
      setProducts(body.data.map(item => ({ id: Number(item.id), name: item.name, cat: item.category, subCategoryId: item.sub_category_id, qty: item.pack_size, price: item.price_paise / 100, stock: Number(item.max_order_quantity || 99), photo: item.image_url || null, emoji: icons[item.category] || '📦', description: item.description, care: item.care_instructions })));
      setCatalogueError(''); setCatalogueReady(true);
    } catch (error) { setCatalogueError(error.message); }
  };
  useEffect(() => { loadCatalogue(); const refresh = () => loadCatalogue(); window.addEventListener('focus', refresh); return () => window.removeEventListener('focus', refresh); }, []);
  const [filter, setFilter] = useState(category);
  const [user, setUser] = useState(null);
  useEffect(() => {
    const refresh = async () => { try { const { data } = await customerApi('me'); setUser(data); } catch { setUser(null); } };
    refresh(); window.addEventListener('dropbell-account-change', refresh);
    return () => window.removeEventListener('dropbell-account-change', refresh);
  }, []);
  const [query, setQuery] = useState('');
  const [cart, setCart] = useState({});
  const [location, setLocation] = useState('');
  const [area, setArea] = useState('');
  const [ready, setReady] = useState(false);
  const [modal, setModal] = useState(null);
  const [toast, setToast] = useState('');
  const searchRef = useRef(null);

  useEffect(() => {
    try {
      const saved = JSON.parse(localStorage.getItem('dropbell-cart') || '{}');
      const clean = {};
      if (saved && typeof saved === 'object' && !Array.isArray(saved)) {
        for (const [id, quantity] of Object.entries(saved)) if (/^[1-9]\d*$/.test(id) && Number.isInteger(quantity) && quantity > 0 && quantity <= 99) clean[id] = quantity;
      }
      setCart(clean);
      setLocation(localStorage.getItem('dropbell-area') || '');
    } catch { /* Storage can be unavailable in private browsing. */ }
    setReady(true);
    const shortcut = event => { if ((event.metaKey || event.ctrlKey) && event.key === 'k') { event.preventDefault(); searchRef.current?.focus(); } };
    document.addEventListener('keydown', shortcut);
    return () => document.removeEventListener('keydown', shortcut);
  }, []);
  useEffect(() => { if (ready) try { localStorage.setItem('dropbell-cart', JSON.stringify(cart)); } catch {} }, [cart, ready]);
  useEffect(() => { if (!toast) return; const timer = setTimeout(() => setToast(''), 2200); return () => clearTimeout(timer); }, [toast]);

  const scrollTo = id => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });
  const chooseFilter = value => { router.push(`/category/${encodeURIComponent(value.toLowerCase())}`, { scroll: false }); };
  useEffect(() => { setFilter(category); setQuery(''); }, [category]);
  const changeQuantity = (id, delta) => {
    const limit = Math.min(99, products.find(product => product.id === id)?.stock || 0);
    setCart(previous => {
      const next = { ...previous };
      next[id] = Math.min(limit, Math.max(0, (next[id] || 0) + delta));
      if (!next[id]) delete next[id];
      return next;
    });
    if (delta > 0 && (cart[id] || 0) < limit) setModal('cart');
  };
  useEffect(() => { if (!catalogueReady || !ready) return; setCart(previous => { const next = {}; for (const product of products) { const quantity = Math.min(previous[product.id] || 0, product.stock, 99); if (quantity > 0) next[product.id] = quantity; } return next; }); }, [products, catalogueReady, ready]);
  const count = Object.values(cart).reduce((total, quantity) => total + quantity, 0);
  const bag = products.filter(product => cart[product.id]);
  const subtotal = bag.reduce((total, product) => total + product.price * cart[product.id], 0);
  const matching = products.filter(product => (filter === 'All' || product.cat.toLowerCase() === filter.toLowerCase()) && `${product.name} ${product.cat}`.toLowerCase().includes(query.toLowerCase().trim()));
  const visible = filter === 'All' && !query.trim() ? matching.slice(0, 5) : matching;

  function Quantity({ product }) {
    if (!product.stock) return <span className="out-of-stock">Out of stock</span>;
    return cart[product.id] ? <div className="stepper" aria-label={`Quantity for ${product.name}`}><button onClick={() => changeQuantity(product.id, -1)} aria-label={`Remove one ${product.name}`}>−</button><span>{cart[product.id]}</span><button onClick={() => changeQuantity(product.id, 1)} aria-label={`Add one ${product.name}`}>+</button></div> : <button className="add" onClick={() => changeQuantity(product.id, 1)} aria-label={`Add ${product.name} to bag`}>ADD +</button>;
  }

  return <>
    <div className="announcement">A little drop. A better day. <span>Everyday essentials, delivered to your door.</span></div>
    <header><Link href="/" aria-label="Dropbell home"><Logo /></Link><button className="location" onClick={() => { setArea(location); setModal('location'); }} aria-haspopup="dialog" aria-expanded={modal === 'location'}><Icon name="pin" className="location-pin" /><span className="location-copy"><small>DELIVERING TO</small><span className="location-value"><strong title={location || 'Choose your location'}>{location || 'Choose your location'}</strong><Icon name="chevron" /></span></span></button><label className="search"><Icon name="search" /><input ref={searchRef} onKeyDown={event => { if (event.key === 'Enter' && query.trim()) router.push(`/category/all?q=${encodeURIComponent(query.trim())}`); }} type="search" value={query} onChange={event => setQuery(event.target.value)} placeholder="Search for milk, bread, and a little more…" aria-label="Search products" /><kbd>⌘ K</kbd></label><Link className="account-header-link" href={user ? '/account' : '/login'}>{user ? 'My account' : 'Sign in'}</Link><button className="cart-button" onClick={() => setModal('cart')}>Bag <span>{count}</span></button></header>
    <CategoryNavigation departments={departments} shortcuts={shortcuts} selected={filter} onSelect={chooseFilter} />
    <main>
      {catalogueError && <div className="catalogue-connection" role="alert"><p>{catalogueError}</p><button className="secondary" onClick={loadCatalogue}>Retry connection</button></div>}
      {!catalogueReady && !catalogueError && <p role="status">Loading the latest essentials…</p>}
      {view === 'product' && catalogueReady && !products.some(product => product.id === productId) && <div className="purchase-empty"><h1>This product is unavailable.</h1><Link href="/category/all" className="primary">Browse essentials →</Link></div>}
      {['login', 'account', 'addresses', 'orders', 'transactions', 'requests', 'help'].includes(view) ? <AccountViews key={view} view={view} products={products} setCart={setCart} /> : ['cart', 'checkout', 'order'].includes(view) ? <PurchaseFlow view={view} orderId={orderId} products={products} cart={cart} setCart={setCart} ready={ready && catalogueReady} location={location} Quantity={Quantity} /> : view === 'catalog' ? <CatalogView managedCategories={managedCategories} subCategories={subCategories} category={filter} products={products} query={query} setQuery={setQuery} chooseFilter={chooseFilter} Quantity={Quantity} /> : view === 'product' ? <ProductView product={products.find(item => item.id === productId)} products={products} Quantity={Quantity} /> : <>

      <section className="hero"><div className="hero-copy"><p className="eyebrow">YOUR NEIGHBOURHOOD, DELIVERED</p><h1>Good things.<br />Just a <em>drop</em> away.</h1><p>From the first cup to the last snack.<br />All your everyday favourites, at your doorstep.</p><button className="primary" onClick={() => scrollTo('categories')}>Fill your day with good things <Icon name="arrow" /></button><div className="hero-notes"><span>✓ Fresh picks</span><span>✓ Everyday prices</span><span>✓ Delivered with care</span></div></div><div className="hero-art"><div className="orbit" /><img src={image('photo-1542838132-92c53300491e', 1100)} alt="An abundant selection of fresh vegetables at a local market" /><div className="fresh-sticker">Fresh finds.<br /><b>Happy homes.</b><span>✳</span></div><div className="delivery-note"><span>⌂</span><div><b>Your everyday, made easier.</b><small>One happy doorstep at a time.</small></div></div></div></section>
      <section className="categories" id="categories"><div className="section-heading"><div><p className="eyebrow">THE DAILY LINEUP</p><h2>What’s on your list?</h2></div><button className="text-button" onClick={() => chooseFilter('All')}>Explore all essentials ↗</button></div><div className="category-grid">{categories.map(([cat, name]) => <button key={name} className="category" onClick={() => chooseFilter(cat)} aria-label={`Shop ${name}`}><div className="category-photo category-image" style={categoryImageStyle(cat)} aria-hidden="true" /><span className="category-name">{name}</span></button>)}</div></section>
      <section id="products"><div className="section-heading"><div><p className="eyebrow">LITTLE THINGS, BIG DIFFERENCE</p><h2>Your everyday favourites</h2></div><span className="subtle">The staples you always come back to.</span></div><div className="filter-row">{filterNames.map(([value, name]) => <button key={value} className={filter.toLowerCase() === value.toLowerCase() ? 'active' : ''} aria-pressed={filter.toLowerCase() === value.toLowerCase()} onClick={() => chooseFilter(value)}>{name}</button>)}<button onClick={() => chooseFilter('All')}>Browse all categories ↗</button></div><div className="product-grid">{visible.map(product => <article className="product" key={product.id}><Link href={`/product/${product.id}`} className="product-photo" aria-label={`View ${product.name}`}>{product.photo ? <img src={image(product.photo)} alt={product.name} loading="lazy" /> : <span className="emoji" role="img" aria-label={product.name}>{product.emoji}</span>}{product.badge && <span className="badge">{product.badge}</span>}</Link><div className="product-body"><small>{categoryName[product.cat] || product.cat}</small><h3><Link href={`/product/${product.id}`}>{product.name}</Link></h3><p className="quantity">{product.qty}</p><div className="product-bottom"><strong>₹{product.price}</strong><Quantity product={product} /></div></div></article>)}</div>{!visible.length && <p role="status">No essentials found. Try another search or category.</p>}</section>
      <section className="promos">{managedCategories.some(item => item.name === 'Breakfast') && <article className="promo breakfast"><div><p className="eyebrow">START ON A GOOD NOTE</p><h2>Breakfast?<br />Consider it sorted.</h2><p>Little morning rituals. All in one basket.</p><button onClick={() => chooseFilter('Breakfast')} className="text-button">Shop breakfast ↗</button></div><img src={image('photo-1490645935967-10de6ba17061', 650)} alt="Fresh breakfast ingredients" /></article>}{managedCategories.some(item => item.name === 'Home') && <article className="promo home"><div><p className="eyebrow">A HAPPY LITTLE RESTOCK</p><h2>A home that’s<br />ready for anything.</h2><p>Keep your cupboards one step ahead.</p><button onClick={() => chooseFilter('Home')} className="text-button">Shop home essentials ↗</button></div><div className="home-illustration" aria-hidden="true">⌂<span>✦</span></div></article>}</section>
      <section className="promise"><div className="promise-title"><p className="eyebrow">THE DROPBELL WAY</p><h2>Small errands.<br />More time for you.</h2></div><div><span className="promise-icon">✳</span><h3>Picked with care</h3><p>Fresh finds and familiar favourites for your everyday.</p></div><div><span className="promise-icon">⌂</span><h3>Right to your door</h3><p>Skip the extra trip. Make room for what matters.</p></div><div><span className="promise-icon">♡</span><h3>A little more ease</h3><p>One simple place for everything on your list.</p></div></section>
      </>}
    </main>
    <footer><div><Logo /><p>Good things, closer home.</p></div><div><b>Here to help</b><a className="footer-link" href={`${process.env.NEXT_PUBLIC_VENDOR_URL || 'http://127.0.0.1:8000'}/vendor/register`}>Sell on Dropbell</a><Link className="footer-link" href="/help">Help & support</Link><button onClick={() => setModal('about')}>About Dropbell</button></div><div className="footer-note">Your everyday, delivered.<small>Products from approved local vendors</small></div><p className="copyright">© 2026 Dropbell. A little drop. A better day.</p></footer>
    {modal === 'location' && <Modal label="Delivery location" onClose={() => setModal(null)}><form onSubmit={event => { event.preventDefault(); const value = area.trim(); if (!value) return; setLocation(value); try { localStorage.setItem('dropbell-area', value); } catch {} loadCatalogue(); setModal(null); setToast('Delivery location saved'); }}><p className="eyebrow">LET’S FIND YOUR DOORSTEP</p><h2>Where should we drop?</h2><p>Choose a delivery area to personalise your experience.</p><label>Delivery postal code<input value={area} onChange={event => setArea(event.target.value)} required pattern="[1-9][0-9]{5}" maxLength={6} inputMode="numeric" placeholder="e.g. 560001" autoComplete="postal-code" /></label><button className="primary" type="submit">Save delivery location →</button><small>Available products and prices depend on your delivery postal code.</small></form></Modal>}
    {modal === 'cart' && <CartDrawer bag={bag} count={count} subtotal={subtotal} Quantity={Quantity} onClose={() => setModal(null)} />}
    {(modal === 'help' || modal === 'about') && <Modal label={modal === 'help' ? 'Help & support' : 'About Dropbell'} onClose={() => setModal(null)}><h2>{modal === 'help' ? 'Here to help' : 'Good things, closer home.'}</h2><p>{modal === 'help' ? 'This is a Dropbell shopping experience preview. Support and order tracking will be available when the service launches.' : 'Dropbell brings groceries and everyday goods to your doorstep. This homepage is an interactive design prototype with sample inventory.'}</p></Modal>}
    <div id="toast" role="status" className={toast ? 'visible' : ''}>{toast}</div>
  </>;
}
