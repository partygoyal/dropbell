'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { CatalogView, ProductView } from './shopping-views';
import { PurchaseFlow } from './purchase-flow';
import { AccountViews } from './account-views';
import CartDrawer from './cart-drawer';
import { customerApi } from '../lib/customer';
import MarketplaceHome, { MarketBrand, MarketIcon } from './marketplace-home';

const categoryName = { Fresh: 'Fruits & vegetables', Breakfast: 'Dairy & breakfast', Home: 'Home essentials', Snacks: 'Snacks & drinks' };
function Icon({ name, className = '' }) {
  return <svg className={`ui-icon ${className}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
    {name === 'pin' && <><path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z" /><circle cx="12" cy="10" r="2.5" /></>}
    {name === 'chevron' && <path d="m6 9 6 6 6-6" />}
    {name === 'search' && <><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 4.5 4.5" /></>}
    {name === 'arrow' && <><path d="M6 18 18 6M6 6h12v12" /></>}
  </svg>;
}

function CategoryNavigation({ departments, selected, onSelect, view, onAbout, onPartner }) {
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
      <summary><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /></svg>All Categories<Icon name="chevron" /></summary>
      <div className="category-menu-panel"><div className="category-menu-heading"><b>Shop your everyday</b><small>Browse every department</small></div><div className="category-menu-grid">{departments.map(([value, name]) => <button type="button" key={value} className={selected.toLowerCase() === value.toLowerCase() ? 'active' : ''} aria-pressed={selected.toLowerCase() === value.toLowerCase()} onClick={() => select(value)}>{name}</button>)}</div></div>
    </details>
    <div className="market-nav-links"><Link className={view === 'home' ? 'active' : ''} href="/">Home</Link><Link href="/#stores">Stores</Link><Link href="/#offers">Offers</Link><Link href="/account/orders">Track Order</Link><a href="/vendor/register">Become a Vendor</a><button type="button" onClick={onPartner}>Delivery Partner</button><button type="button" onClick={onAbout}>About Us</button></div>
  </nav>;
}

function Logo() { return <MarketBrand />; }

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
  const [catalogueReady, setCatalogueReady] = useState(false);
  const [catalogueError, setCatalogueError] = useState('');
  const loadCatalogue = async () => {
    try {
      let pin = ''; try { pin = localStorage.getItem('dropbell-area') || ''; } catch {}
      const query = /^[1-9]\d{5}$/.test(pin) ? `?postal_code=${pin}` : '';
      const response = await fetch(`/api/catalog${query}`, { cache: 'no-store' });
      if (!response.ok) throw new Error('The catalogue is temporarily unavailable. Please try again shortly.');
      const body = await response.json();
      const legacyReplacements = { Fresh: 'Fruits & Vegetables', Breakfast: 'Dairy, Bread & Eggs', Snacks: 'Snacks & Namkeen', Home: 'Cleaning & Household Essentials' };
      const catalogueCategories = body.categories || [];
      setManagedCategories(catalogueCategories.filter(item => !legacyReplacements[item.name] || !catalogueCategories.some(other => other.name === legacyReplacements[item.name]) || body.data.some(product => product.category === item.name)));
      setSubCategories(body.sub_categories || []);
      const icons = { 'Fruits & Vegetables': '🥬', 'Dairy, Bread & Eggs': '🥛', 'Atta, Rice & Pulses': '🌾', 'Oils, Spices & Cooking Essentials': '🫙', 'Snacks & Namkeen': '🍿', 'Biscuits, Chocolates & Sweets': '🍫', 'Breakfast & Spreads': '🍯', 'Instant & Packaged Foods': '🍜', 'Sauces, Pickles & Condiments': '🥫', Beverages: '🧃', 'Frozen Foods & Ice Cream': '🍦', 'Personal Care & Hygiene': '🧼', 'Beauty & Grooming': '🧴', 'Cleaning & Household Essentials': '🧹', 'Baby Care': '🍼', 'Pet Care': '🐾', Fresh: '🥬', Breakfast: '🥛', Snacks: '🍿', Home: '🧴' };
      setProducts(body.data.map(item => ({ id: Number(item.id), name: item.name, cat: item.category, subCategoryId: item.sub_category_id, qty: item.pack_size, price: item.price_paise / 100, stock: item.is_sample ? Math.min(99, Number(item.stock_quantity ?? 0)) : Number(item.max_order_quantity ?? 99), inventory: item.stock_quantity, sample: !!item.is_sample, photo: item.image_url || null, emoji: icons[item.category] || '📦', description: item.description, care: item.care_instructions })));
      setCatalogueError(''); setCatalogueReady(true);
    } catch { setCatalogueError('The catalogue is temporarily unavailable. Please try again shortly.'); }
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


  function Quantity({ product }) {
    if (!product.stock) return <span className="out-of-stock">Out of stock</span>;
    return cart[product.id] ? <div className="stepper" aria-label={`Quantity for ${product.name}`}><button onClick={() => changeQuantity(product.id, -1)} aria-label={`Remove one ${product.name}`}>−</button><span>{cart[product.id]}</span><button onClick={() => changeQuantity(product.id, 1)} aria-label={`Add one ${product.name}`} disabled={cart[product.id] >= product.stock}>+</button></div> : <button className="add" onClick={() => changeQuantity(product.id, 1)} aria-label={`Add ${product.name} to cart`}>Add to Cart</button>;
  }

  return <>
    <header className="market-header"><Link href="/" aria-label="Dropbell home"><Logo /></Link><button className="location" onClick={() => { setArea(location); setModal('location'); }} aria-label="Choose delivery location" aria-haspopup="dialog" aria-expanded={modal === 'location'}><Icon name="pin" className="location-pin" /><span className="location-copy"><span className="location-value"><strong title={location || 'Dehradun'}>{location || 'Dehradun'}</strong><Icon name="chevron" /></span><small>{location ? 'Delivery postal code' : 'Uttarakhand, India'}</small></span></button><form className="search" role="search" onSubmit={event => { event.preventDefault(); if (query.trim()) router.push(`/category/all?q=${encodeURIComponent(query.trim())}`); }}><input ref={searchRef} type="search" value={query} onChange={event => setQuery(event.target.value)} placeholder="Search for products, stores and more..." aria-label="Search products" /><button type="submit" aria-label="Search"><Icon name="search" /></button></form><Link className="account-header-link" href={user ? '/account' : '/login'}><MarketIcon name="user" /><span>{user ? 'My Account' : 'Login / Sign Up'}</span></Link><button className="cart-button" onClick={() => setModal('cart')} aria-label={`Open cart, ${count} items`}><MarketIcon name="cart" /><span className="market-cart-count">{count}</span><b>Cart</b></button></header>
    <CategoryNavigation departments={departments} selected={filter} onSelect={chooseFilter} view={view} onAbout={() => setModal('about')} onPartner={() => setModal('partner')} />
    <main className={view === 'home' ? 'market-main' : 'market-inner-main'}>
      {catalogueError && <div className="catalogue-connection" role="alert"><p>{catalogueError}</p><button className="secondary" onClick={loadCatalogue}>Retry connection</button></div>}
      {products.some(product => product.sample) && <p className="catalogue-sample-notice" role="note">Sample catalogue: labelled products show illustrative prices and quantities. Try Add to Cart to explore your bag. Checkout creates a labelled test Cash on Delivery order. No money is collected for sample items.</p>}
      {!catalogueReady && !catalogueError && <p role="status">Loading the latest essentials…</p>}
      {view === 'product' && catalogueReady && !products.some(product => product.id === productId) && <div className="purchase-empty"><h1>This product is unavailable.</h1><Link href="/category/all" className="primary">Browse essentials →</Link></div>}
      {['login', 'account', 'addresses', 'orders', 'transactions', 'requests', 'help'].includes(view) ? <AccountViews key={view} view={view} products={products} setCart={setCart} /> : ['cart', 'checkout', 'order'].includes(view) ? <PurchaseFlow view={view} orderId={orderId} products={products} cart={cart} setCart={setCart} ready={ready && catalogueReady} location={location} Quantity={Quantity} /> : view === 'catalog' ? <CatalogView managedCategories={managedCategories} subCategories={subCategories} category={filter} products={products} query={query} setQuery={setQuery} chooseFilter={chooseFilter} Quantity={Quantity} /> : view === 'product' ? <ProductView product={products.find(item => item.id === productId)} products={products} Quantity={Quantity} /> : <MarketplaceHome managedCategories={managedCategories} subCategories={subCategories} products={products} Quantity={Quantity} chooseFilter={chooseFilter} />}
    </main>
    <footer className="market-footer"><div className="market-footer-top"><Link href="/" aria-label="Dropbell home"><Logo /></Link><div className="market-footer-links"><button onClick={() => setModal('about')}>About Us</button><Link href="/help">Contact Us</Link><button onClick={() => setModal('terms')}>Terms & Conditions</button><button onClick={() => setModal('privacy')}>Privacy Policy</button></div><div className="market-footer-help"><b>Stay Connected</b><a href="mailto:settings@dropbell.in">settings@dropbell.in</a></div><div className="market-footer-signoff"><MarketBrand caption={false} /><span>Delivery on Time</span></div></div><div className="market-footer-bottom"><small>© {new Date().getFullYear()} Dropbell Delivery Company Private Limited. All rights reserved.</small><span><MarketIcon name="pin" />Dehradun, Uttarakhand, India</span></div></footer>
    {modal === 'location' && <Modal label="Delivery location" onClose={() => setModal(null)}><form onSubmit={event => { event.preventDefault(); const value = area.trim(); if (!value) return; setLocation(value); try { localStorage.setItem('dropbell-area', value); } catch {} loadCatalogue(); setModal(null); setToast('Delivery location saved'); }}><p className="eyebrow">LET’S FIND YOUR DOORSTEP</p><h2>Where should we drop?</h2><p>Choose a delivery area to personalise your experience.</p><label>Delivery postal code<input value={area} onChange={event => setArea(event.target.value)} required pattern="[1-9][0-9]{5}" maxLength={6} inputMode="numeric" placeholder="e.g. 248001" autoComplete="postal-code" /></label><button className="primary" type="submit">Save delivery location →</button><small>Available products and prices depend on your delivery postal code.</small></form></Modal>}
    {modal === 'cart' && <CartDrawer bag={bag} count={count} subtotal={subtotal} Quantity={Quantity} onClose={() => setModal(null)} />}
    {['help', 'about', 'partner', 'terms', 'privacy'].includes(modal) && <Modal label={{ help: 'Help & support', about: 'About Dropbell', partner: 'Become a delivery partner', terms: 'Terms & conditions', privacy: 'Privacy information' }[modal]} onClose={() => setModal(null)}><h2>{{ help: 'Here to help', about: 'Your neighbourhood, delivered.', partner: 'Deliver with Dropbell', terms: 'Shopping with Dropbell', privacy: 'Your privacy matters' }[modal]}</h2><p>{{ help: 'Find answers to your questions in our help centre, or contact settings@dropbell.in.', about: 'Dropbell connects you with local vendors for groceries, fresh produce, dairy, household essentials and more. Discover your neighbourhood’s everyday favourites, all in one place.', partner: 'Interested in delivering with Dropbell in Dehradun? Contact our team to learn about delivery partner onboarding.', terms: 'Available products, prices and delivery options depend on your postal code and local vendors. Review your order details before confirming. Contact us for the current service terms.', privacy: 'Dropbell uses your account, address and order information to provide shopping and delivery services. Your bag and delivery postal code are saved in this browser. Contact our team for privacy questions.' }[modal]}</p>{modal === 'partner' && <a className="primary" href="mailto:settings@dropbell.in?subject=Delivery%20partner%20enquiry">Contact the team →</a>}</Modal>}
    <div id="toast" role="status" className={toast ? 'visible' : ''}>{toast}</div>
  </>;
}
