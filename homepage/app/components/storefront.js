'use client';

import { useEffect, useRef, useState, useMemo } from 'react';
import Link from 'next/link';
import dynamic from 'next/dynamic';
import { fetchJson } from '../lib/http.mjs';
import { saveCart, getCartVendor, switchCartVendor, readCart, readCartOptions, saveCartWithOptions } from '../lib/cart-storage.mjs';
import ShopBrowser from './shop-browser';
import SiteFooter from './site-footer';
import SiteIcon from './site-icon';
import OffersPage from './offers-page';
import {DishOptions} from './discovery-features';
import ShoppingTabs from './shopping-tabs';
import FoodHome from './food-home';
import { sectionCatalogue } from '../lib/shopping-sections.mjs';
import { useRouter } from 'next/navigation';
import { CatalogView, ProductView } from './shopping-views';
const PurchaseFlow = dynamic(() => import('./purchase-flow').then(module => module.PurchaseFlow), { loading: () => <p role="status">Loading checkout…</p> });
const AccountViews = dynamic(() => import('./account-views').then(module => module.AccountViews), { loading: () => <p role="status">Loading your account…</p> });
import DeliveryLocation from './delivery-pin';
import PromotionBanner from './promotion-banner';
const CartDrawer = dynamic(() => import('./cart-drawer'), { loading: () => <p role="status">Loading your bag…</p> });
import { customerApi } from '../lib/customer';
import MarketplaceHome, { MarketBrand, MarketIcon } from './marketplace-home';

const categoryName = { Fresh: 'Fruits & vegetables', Breakfast: 'Dairy & breakfast', Home: 'Home essentials', Snacks: 'Snacks & drinks' };
function Icon({ name, className = '' }) {
  return <SiteIcon name={name === 'arrow' ? 'arrow-up-right' : name} className={`ui-icon ${className}`} />;
}

function CategoryNavigation({ departments, selected, onSelect, view, section = 'groceries', onAbout, onPartner }) {
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
      <summary><SiteIcon name="grid" size={18} />All Categories<Icon name="chevron" /></summary>
      <div className="category-menu-panel"><div className="category-menu-heading"><b>Shop your everyday</b><small>Browse every department</small></div><div className="category-menu-grid">{departments.map(([value, name]) => <button type="button" key={value} className={selected.toLowerCase() === value.toLowerCase() ? 'active' : ''} aria-pressed={selected.toLowerCase() === value.toLowerCase()} onClick={() => select(value)}>{name}</button>)}</div></div>
    </details>
    <div className="market-nav-links"><Link className={view === 'home' ? 'active' : ''} href={section === 'food' ? '/food' : '/groceries'}>Home</Link><Link href="/shops">{section === 'food' ? 'Restaurants' : 'Stores'}</Link><Link href="/offers">Offers</Link><Link href="/account/orders">Track Order</Link><a href="/vendor/register">Become a Vendor</a><button type="button" onClick={onPartner}>Delivery Partner</button><button type="button" onClick={onAbout}>About Us</button></div>
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
  }}><button className="close" onClick={onClose} aria-label="Close"><SiteIcon name="close" /></button>{children}</dialog>;
}

export default function Storefront({ category = 'All', productId = null, view = 'home', orderId = null, vendorId = null, shoppingSection = null }) {
  const router = useRouter();
  const [section, setSection] = useState(shoppingSection || 'groceries');
  useEffect(() => {
    let selected = shoppingSection;
    try { selected ||= localStorage.getItem('dropbell-shopping-section'); } catch {}
    selected = selected === 'food' ? 'food' : 'groceries';
    setSection(selected);
    try { localStorage.setItem('dropbell-shopping-section', selected); } catch {}
  }, [shoppingSection]);
  const [catalogueProducts, setProducts] = useState([]);
  const [customizations,setCustomizations]=useState({});
  const [customProduct,setCustomProduct]=useState(null);
  const products=useMemo(()=>catalogueProducts.map(product=>{const configuration=customizations[product.id];return {...product,basePrice:product.price,qty:product.qty+(configuration?.ids?.length?' · '+(product.optionGroups||[]).flatMap(g=>g.choices).filter(c=>configuration.ids.includes(c.id)).map(c=>c.name).join(', '):''),price:product.price+(configuration?.vendorId===product.optionVendorId?(configuration.extra||0)/100:0)};}),[catalogueProducts,customizations]);
  const [vendors, setVendors] = useState([]);
  const [shopVendorId, setShopVendorId] = useState(null);
  const [hasDeliveryLocation, setHasDeliveryLocation] = useState(false);
  const [managedCategories, setManagedCategories] = useState([]);
  const [subCategories, setSubCategories] = useState([]);
  const selectedCatalogue = sectionCatalogue({ products, categories: managedCategories, subCategories, vendors }, section);
  const departments = [['All', section === 'food' ? 'All food' : 'All essentials'], ...selectedCatalogue.categories.map(item => [item.name, categoryName[item.name] || item.name])];
  const [catalogueReady, setCatalogueReady] = useState(false);
  const [catalogueError, setCatalogueError] = useState('');
  const catalogueRequest = useRef(null);
  const loadCatalogue = async () => {
    // Ignore superseded responses instead of aborting fetch during React cleanup.
    // The transport still enforces its timeout for stalled requests.
    if (catalogueRequest.current) catalogueRequest.current.cancelled = true;
    const request = { cancelled: false };
    catalogueRequest.current = request;
    try {
      let locality='';try {locality=localStorage.getItem('dropbell-locality')||'';}catch{}
      let activeVendor = null;
      try { activeVendor = getCartVendor(localStorage); } catch {}
      const params = new URLSearchParams();
      if (/^[1-9]\d*$/.test(locality)) params.set('locality_id', locality);
      if (activeVendor) params.set('vendor_id', String(activeVendor));
      const body = await fetchJson(`/api/catalog?${params}`, { cache: 'no-store' });
      if (request.cancelled) return;
      if (!Array.isArray(body.data)) throw new Error('Invalid catalogue');
      setShopVendorId(activeVendor);
      setHasDeliveryLocation(/^[1-9]\d*$/.test(locality));
      setVendors(Array.isArray(body.vendors) ? body.vendors : []);
      const legacyReplacements = { Fresh: 'Fruits & Vegetables', Breakfast: 'Dairy, Bread & Eggs', Snacks: 'Snacks & Namkeen', Home: 'Cleaning & Household Essentials' };
      const catalogueCategories = body.categories || [];
      setManagedCategories(catalogueCategories.filter(item => !legacyReplacements[item.name] || !catalogueCategories.some(other => other.name === legacyReplacements[item.name]) || body.data.some(product => product.category === item.name)));
      setSubCategories(body.sub_categories || []);
      const icons = { 'Fruits & Vegetables': '🥬', 'Dairy, Bread & Eggs': '🥛', 'Atta, Rice & Pulses': '🌾', 'Oils, Spices & Cooking Essentials': '🫙', 'Snacks & Namkeen': '🍿', 'Biscuits, Chocolates & Sweets': '🍫', 'Breakfast & Spreads': '🍯', 'Instant & Packaged Foods': '🍜', 'Sauces, Pickles & Condiments': '🥫', Beverages: '🧃', 'Frozen Foods & Ice Cream': '🍦', 'Personal Care & Hygiene': '🧼', 'Beauty & Grooming': '🧴', 'Cleaning & Household Essentials': '🧹', 'Baby Care': '🍼', 'Pet Care': '🐾', Fresh: '🥬', Breakfast: '🥛', Snacks: '🍿', Home: '🧴' };
      setProducts(body.data.map(item => ({ id: Number(item.id), name: item.name, cat: item.category, subCategoryId: item.sub_category_id, qty: item.pack_size, price: item.price_paise / 100, stock: item.is_sample ? Math.min(99, Number(item.stock_quantity ?? 0)) : Number(item.max_order_quantity ?? 99), inventory: item.stock_quantity, sample: !!item.is_sample, section: item.shopping_section || 'groceries', optionGroups:item.option_groups||[], optionVendorId:item.option_vendor_id, menuRevision:item.menu_revision||0, dietaryLabel:item.dietary_label, allergens:item.allergens||[], rating:item.rating, reviewCount:item.review_count||0, photo: item.image_url || null, emoji: icons[item.category] || '📦', description: item.description, care: item.care_instructions })));
      setCatalogueError(''); setCatalogueReady(true);
    } catch { if (!request.cancelled) setCatalogueError('The catalogue is temporarily unavailable. Please try again shortly.'); }
  };
  useEffect(() => {
    loadCatalogue();
    const refresh = () => loadCatalogue();
    window.addEventListener('focus', refresh);
    return () => {
      if (catalogueRequest.current) catalogueRequest.current.cancelled = true;
      window.removeEventListener('focus', refresh);
    };
  }, []);
  const [filter, setFilter] = useState(category);
  const [user, setUser] = useState(null);
  useEffect(() => {
    const refresh = async () => { try { const { data } = await customerApi('me'); setUser(data); } catch (error) { if (error.status === 401) setUser(null); } };
    refresh(); window.addEventListener('dropbell-account-change', refresh);
    return () => window.removeEventListener('dropbell-account-change', refresh);
  }, []);
  const [query, setQuery] = useState('');
  const [cart, setCart] = useState({});
  const [location, setLocation] = useState('');
  const [area, setArea] = useState('');
  const [chosenLocation,setChosenLocation]=useState({});
  const [ready, setReady] = useState(false);
  const [modal, setModal] = useState(null);
  const [toast, setToast] = useState('');
  const searchRef = useRef(null);

  useEffect(() => {
    try {
      setCart(readCart(localStorage)); setCustomizations(readCartOptions(localStorage));
      setLocation(localStorage.getItem('dropbell-location-label') || '');
    } catch { /* Storage can be unavailable in private browsing. */ }
    setReady(true);
    const shortcut = event => { if ((event.metaKey || event.ctrlKey) && event.key === 'k') { event.preventDefault(); searchRef.current?.focus(); } };
    const syncBag = event => {
      if (event.key !== 'dropbell-cart' && event.key !== 'dropbell-locality') return;
      try {
        setCart(readCart(localStorage)); setCustomizations(readCartOptions(localStorage));
        setShopVendorId(getCartVendor(localStorage));
        setLocation(localStorage.getItem('dropbell-location-label') || '');
        setProducts([]); setCatalogueReady(false);
        loadCatalogue();
      } catch { setToast('Could not sync your saved bag. Reload before checking out.'); }
    };
    document.addEventListener('keydown', shortcut);
    window.addEventListener('storage', syncBag);
    return () => { document.removeEventListener('keydown', shortcut); window.removeEventListener('storage', syncBag); };
  }, []);
  useEffect(() => { if (ready) try { saveCartWithOptions(localStorage, cart, customizations); } catch {} }, [cart, customizations, ready]);
  useEffect(() => { if (!toast) return; const timer = setTimeout(() => setToast(''), 2200); return () => clearTimeout(timer); }, [toast]);

  const chooseFilter = value => { router.push(`/category/${encodeURIComponent(value.toLowerCase())}`, { scroll: false }); };
  useEffect(() => { setFilter(category); }, [category]);
  const changeQuantity = (id, delta) => {
    try {
      if (getCartVendor(localStorage) !== shopVendorId) { setToast('Your shop changed in another tab. Refresh before changing your bag.'); return; }
    } catch { /* Browsing remains available if storage is disabled. */ }
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


  const chooseVendor = nextVendorId => {
    if (nextVendorId === shopVendorId) return;
    if (count && !window.confirm('Changing shops clears your current bag so each item uses the selected shop’s price and availability. Continue?')) return;
    try {
      switchCartVendor(localStorage, nextVendorId);
    } catch {
      setToast('Could not save your shop selection. Check browser storage and try again.');
      return;
    }
    setCart({}); setCustomizations({}); setProducts([]); setCatalogueReady(false); setShopVendorId(nextVendorId);
    loadCatalogue();
    if (!nextVendorId && view === 'shop') router.push('/shops');
  };
  const chooseLocation = () => setModal('location');
  useEffect(() => {
    const product = view === 'product' && products.find(item => item.id === productId);
    const vendor = view === 'shop' && vendors.find(item => Number(item.id) === vendorId);
    const selectedCategory = view === 'catalog' && managedCategories.find(item => item.name.toLowerCase() === category.toLowerCase());
    const selected = product?.section || selectedCategory?.shopping_section || (vendor && !vendor.sections?.includes(section) && vendor.sections?.[0]);
    if (selected && selected !== section) {
      setSection(selected);
      try { localStorage.setItem('dropbell-shopping-section', selected); } catch {}
    }
  }, [view, products, productId, vendors, vendorId, section, managedCategories, category]);
  const activeShop = vendors.find(item => Number(item.id) === shopVendorId);

  const reorder = async reference => {
    try {
      if(!user)throw Error('Sign in before reordering.');
      const pendingKey=`dropbell-pending-checkout:${user.email||user.phone}`;if(sessionStorage.getItem(pendingKey))throw Error('Recover your saved checkout before replacing the bag.');
      const locality=Number(localStorage.getItem('dropbell-locality'));if(!locality)throw Error('Choose a delivery location before reordering.');
      const result=await customerApi(`orders/${reference}/reorder`,{method:'POST',body:{locality_id:locality}});const {items,unavailable}=result.data;
      if(!items.length)throw Error('None of these items are currently available for this delivery location.');
      if(!window.confirm(`Replace your bag with ${items.length} available items? ${unavailable.length} items could not be reordered. Current prices apply.`))return;
      const ids=[...new Set(items.map(item=>item.vendor_id).filter(Boolean))];const selectedVendor=ids.length===1?ids[0]:null;
      const nextCart=Object.fromEntries(items.map(item=>[item.product.id,item.quantity]));
      const options=Object.fromEntries(items.map(item=>[item.product.id,{ids:item.options||[],vendorId:item.vendor_id,extra:(item.product.option_groups||[]).flatMap(g=>g.choices).filter(c=>(item.options||[]).includes(c.id)).reduce((n,c)=>n+c.price_paise,0),menuRevision:item.product.menu_revision||0}]));
      switchCartVendor(localStorage,selectedVendor);saveCartWithOptions(localStorage,nextCart,options);setCatalogueReady(false);setProducts([]);setCustomizations(options);setCart(nextCart);setShopVendorId(selectedVendor);await loadCatalogue();router.push('/cart');
    }catch(error){setToast(error.message);}
  };

  function Quantity({ product }) {
    if (!product.stock) return <span className="out-of-stock">Out of stock</span>;
    if (product.optionGroups?.length && (!cart[product.id] || !customizations[product.id])) return <button className="add" onClick={()=>{setCustomProduct(product);setModal('customize');}}>Choose options</button>;
    return cart[product.id] ? <div className="stepper" aria-label={`Quantity for ${product.name}`}><button onClick={() => changeQuantity(product.id, -1)} aria-label={`Remove one ${product.name}`}><SiteIcon name="minus" size={14} /></button><span>{cart[product.id]}</span><button onClick={() => changeQuantity(product.id, 1)} aria-label={`Add one ${product.name}`} disabled={cart[product.id] >= product.stock}><SiteIcon name="plus" size={14} /></button></div> : <button className="add" onClick={() => changeQuantity(product.id, 1)} aria-label={`Add ${product.name} to cart`}>Add to Cart</button>;
  }

  return <>
    <div className="vendor-access-bar"><span>{section === 'food' ? 'Run a restaurant?' : 'Run a local shop?'}</span><a className="vendor-login-button" href="https://dropbell.in/vendor/login"><MarketIcon name="store" /><span>Vendor Login</span><span aria-hidden="true"><SiteIcon name="arrow" size={16} /></span></a></div>
    <header className="market-header"><Link href="/" aria-label="Dropbell home"><Logo /></Link><button className="location" onClick={() => { setArea(location); setModal('location'); }} aria-label="Choose delivery location" aria-haspopup="dialog" aria-expanded={modal === 'location'}><Icon name="pin" className="location-pin" /><span className="location-copy"><span className="location-value"><strong title={location || 'Select location'}>{location || 'Select location'}</strong><Icon name="chevron" /></span><small>{location ? 'Your delivery locality' : 'State, City, Locality'}</small></span></button><form className="search" role="search" onSubmit={event => { event.preventDefault(); if (query.trim()) router.push(`/category/all?q=${encodeURIComponent(query.trim())}`); }}><input ref={searchRef} type="search" value={query} onChange={event => setQuery(event.target.value)} placeholder={section === 'food' ? 'Search for dishes…' : 'Search for products, stores and more...'} aria-label="Search products" /><button type="submit" aria-label="Search"><Icon name="search" /></button></form><Link className="account-header-link" href={user ? '/account' : '/login'}><MarketIcon name="user" /><span>{user ? 'My Account' : 'Login / Sign Up'}</span></Link><button className="cart-button" onClick={() => setModal('cart')} aria-label={`Open cart, ${count} items`}><MarketIcon name="cart" /><span className="market-cart-count">{count}</span><b>Cart</b></button></header>
    <CategoryNavigation departments={departments} selected={filter} onSelect={chooseFilter} view={view} onAbout={() => setModal('about')} onPartner={() => setModal('partner')} section={section} />
    {['home', 'catalog', 'shops', 'shop', 'product'].includes(view) && <ShoppingTabs section={section} />}
    {view === 'home' && <PromotionBanner key={section} section={section} />}
    <main className={view === 'home' ? 'market-main' : 'market-inner-main'}>
      {shopVendorId && <div className="shop-context"><span>Shopping from <b>{activeShop?.name || 'your selected shop'}</b>. Prices and checkout use this shop.</span><button type="button" onClick={() => chooseVendor(null)}>Shop all local stores</button></div>}
      {catalogueError && <div className="catalogue-connection" role="alert"><p>{catalogueError}</p><button className="secondary" onClick={loadCatalogue}>Retry connection</button></div>}
      {products.some(product => product.sample) && <p className="catalogue-sample-notice" role="note">Sample catalogue: labelled products show illustrative prices and quantities. Try Add to Cart to explore your bag. Checkout creates a labelled test Cash on Delivery order. No money is collected for sample items.</p>}
      {!catalogueReady && !catalogueError && <p role="status">Loading the latest essentials…</p>}
      {view === 'product' && catalogueReady && !products.some(product => product.id === productId) && <div className="purchase-empty"><h1>This product is unavailable.</h1><Link href="/category/all" className="primary">Browse essentials <SiteIcon name="arrow" size={16} /></Link></div>}
      {view === 'offers' ? <OffersPage categories={managedCategories} products={products} /> : ['login', 'account', 'addresses', 'orders', 'transactions', 'requests', 'help', 'support', 'favourites'].includes(view) ? <AccountViews key={view} view={view} products={products} setCart={setCart} onReorder={reorder} /> : ['cart', 'checkout', 'order'].includes(view) ? <PurchaseFlow view={view} orderId={orderId} products={products} cart={cart} setCart={setCart} ready={ready && catalogueReady} location={location} Quantity={Quantity} vendorId={shopVendorId} customizations={customizations} onReorder={reorder} /> : ['shops', 'shop'].includes(view) ? <ShopBrowser section={section} vendorId={vendorId} vendors={selectedCatalogue.vendors} activeVendorId={shopVendorId} products={selectedCatalogue.products} Quantity={Quantity} hasLocation={hasDeliveryLocation} onChooseLocation={chooseLocation} onChooseVendor={chooseVendor} ready={catalogueReady} query={query} setQuery={setQuery} /> : view === 'catalog' ? <CatalogView key={`${section}:${category}`} managedCategories={selectedCatalogue.categories} subCategories={selectedCatalogue.subCategories} category={filter} products={selectedCatalogue.products} query={query} setQuery={setQuery} chooseFilter={chooseFilter} Quantity={Quantity} /> : view === 'product' ? <ProductView product={products.find(item => item.id === productId)} products={products} Quantity={Quantity} /> : section === 'food' ? <FoodHome products={selectedCatalogue.products} vendors={selectedCatalogue.vendors} categories={selectedCatalogue.categories} Quantity={Quantity} hasLocation={hasDeliveryLocation} ready={catalogueReady} chooseFilter={chooseFilter} /> : <MarketplaceHome vendors={selectedCatalogue.vendors} hasLocation={hasDeliveryLocation} onChooseLocation={chooseLocation} catalogueReady={catalogueReady} managedCategories={selectedCatalogue.categories} subCategories={selectedCatalogue.subCategories} products={selectedCatalogue.products} Quantity={Quantity} chooseFilter={chooseFilter} />}
    </main>
    <SiteFooter section={section} categories={selectedCatalogue.categories} onOpenModal={setModal}/>
    {modal === 'location' && <Modal label="Delivery location" onClose={()=>setModal(null)}><h2>Select delivery location</h2><DeliveryLocation value={chosenLocation} onChange={setChosenLocation}/><button className="primary" disabled={!chosenLocation.locality_id} onClick={()=>{if(count && !window.confirm('Changing delivery location clears your current bag. Continue?'))return;const label=`${chosenLocation.locality}, ${chosenLocation.city}`;try{switchCartVendor(localStorage,null);localStorage.setItem('dropbell-locality',String(chosenLocation.locality_id));localStorage.setItem('dropbell-location-label',label);localStorage.setItem('dropbell-area',chosenLocation.pin);}catch{setToast('Could not save your delivery location. Please try again.');return;}setLocation(label);setCart({});setProducts([]);setCatalogueReady(false);setShopVendorId(null);loadCatalogue();setModal(null);setToast('Delivery location saved. Your bag is ready for the new area.');}}>Use this location</button></Modal>}
    {modal === 'customize' && customProduct && <Modal label="Dish options" onClose={()=>setModal(null)}><DishOptions product={customProduct} initial={customizations[customProduct.id]} onSave={configuration=>{setCustomizations(previous=>({...previous,[customProduct.id]:configuration}));setCart(previous=>({...previous,[customProduct.id]:1}));setModal('cart');}}/></Modal>}
    {modal === 'cart'  && <CartDrawer bag={bag} count={count} subtotal={subtotal} Quantity={Quantity} onClose={() => setModal(null)} />}
    {['help', 'about', 'partner', 'terms', 'privacy'].includes(modal) && <Modal label={{ help: 'Help & support', about: 'About Dropbell', partner: 'Become a delivery partner', terms: 'Terms & conditions', privacy: 'Privacy information' }[modal]} onClose={() => setModal(null)}><h2>{{ help: 'Here to help', about: 'Your neighbourhood, delivered.', partner: 'Deliver with Dropbell', terms: 'Shopping with Dropbell', privacy: 'Your privacy matters' }[modal]}</h2><p>{{ help: 'Find answers to your questions in our help centre, or contact settings@dropbell.in.', about: 'Dropbell connects you with local vendors for groceries, fresh produce, dairy, household essentials and more. Discover your neighbourhood’s everyday favourites, all in one place.', partner: 'Interested in delivering with Dropbell in Dehradun? Contact our team to learn about delivery partner onboarding.', terms: 'Available products, prices and delivery options depend on your postal code and local vendors. Review your order details before confirming. Contact us for the current service terms.', privacy: 'Dropbell uses your account, address and order information to provide shopping and delivery services. Your bag and delivery postal code are saved in this browser. Contact our team for privacy questions.' }[modal]}</p>{modal === 'partner' && <a className="primary" href="mailto:settings@dropbell.in?subject=Delivery%20partner%20enquiry">Contact the team <SiteIcon name="arrow" size={16} /></a>}</Modal>}
    <div id="toast" role="status" className={toast ? 'visible' : ''}>{toast}</div>
  </>;
}
