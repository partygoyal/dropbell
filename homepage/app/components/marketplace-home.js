'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { categoryImageStyle, subCategoryImageStyle } from '../lib/category-images';

export function MarketIcon({ name, className = '' }) {
  const paths = {
    menu: <path d="M4 6h16M4 12h16M4 18h16" />,
    arrow: <path d="M4 12h16m-6-6 6 6-6 6" />,
    user: <><circle cx="12" cy="7" r="4" /><path d="M4 22v-3a8 8 0 0 1 16 0v3" /></>,
    cart: <><path d="M2 3h3l3 13h11l3-9H6" /><circle cx="9" cy="21" r="1" /><circle cx="18" cy="21" r="1" /></>,
    store: <><path d="m3 9 2-6h14l2 6v4a3 3 0 0 1-4 0 3 3 0 0 1-5 0 3 3 0 0 1-5 0 3 3 0 0 1-4 0V9Zm2 7v6h14v-6M9 22v-6h6v6M3 9h18" /></>,
    leaf: <><path d="M21 3C6 1 1 9 5 17c8 6 17 0 16-14ZM3 21 17 7M9 15v-5m5 0h4" /></>,
    shield: <><path d="m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6l9-4Z" /><path d="m7 12 3 3 7-7" /></>,
    truck: <><path d="M2 5h12v12H2V5Zm12 5h5l3 4v3h-8M2 10h7" /><circle cx="6" cy="19" r="2" /><circle cx="18" cy="19" r="2" /></>,
    rupee: <path d="M5 3h14M5 7h14M8 3a6 6 0 0 1 0 12H5l11 7" />,
    support: <><path d="M3 14v-3a9 9 0 0 1 18 0v3M5 11H2v7h3V11Zm14 0h3v7h-3v-7Zm0 7v2c0 2-7 2-7 2" /></>,
    milk: <><path d="M8 2h8v5l3 4v11H5V11l3-4V2ZM8 7h8M5 13h14" /><path d="M10 17h4" /></>,
    bread: <><path d="M5 10C0 9 1 2 7 3a6 6 0 0 1 10 0c6-1 7 6 2 7v11H5V10Z" /><path d="m8 9 2-3m4 3 2-3" /></>,
    sweet: <><path d="M4 8h16v13H4V8Zm-1 0V5h18v3M12 5v16M4 12h16" /><path d="M12 5C4-3 2 6 12 5Zm0 0c8-8 10 1 0 0Z" /></>,
    pin: <><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z" /><circle cx="12" cy="10" r="2.5" /></>,
  };
  return <svg className={`market-icon ${className}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[name] || paths.store}</svg>;
}

export function MarketBrand({ hero = false }) {
  return <span className={`market-brand${hero ? ' market-brand-hero' : ''}`}>
    <svg className="market-brand-mark" viewBox="0 0 62 64" fill="currentColor" aria-hidden="true"><path d="M15 4h17c17 0 27 10 27 26S47 57 29 57H4l8-37h12l-6 26h13c9 0 15-6 15-15S41 16 32 16H12l3-12Z" /><path d="M25 34c3-2 2-6 4-8 1-2 5-2 6 0 2 2 1 6 4 8v3H25v-3Zm5 5h4c0 4-4 4-4 0Z" /><path d="M4 12h13v3H4Zm-3 6h13v3H1Z" /></svg>
    <span><b>Dropbell</b><small>{hero ? 'Sab Kuch, Ab Aapke Ghar Tak' : 'Delivery Company Private Limited'}</small></span>
  </span>;
}

const shoppingCategories = [
  { label: 'Groceries', match: /grocery|atta|rice|pulses/i, art: 'Atta, Rice & Pulses' },
  { label: 'Vegetables', match: /fruit|vegetable|fresh/i, sub: /fresh vegetables/i, art: 'Fruits & Vegetables', photo: 'Fresh Vegetables' },
  { label: 'Fruits', match: /fruit|vegetable|fresh/i, sub: /fresh fruits/i, art: 'Fruits & Vegetables', photo: 'Fresh Fruits' },
  { label: 'Sweets', match: /sweet|chocolate|snack/i, art: 'Biscuits, Chocolates & Sweets' },
  { label: 'Dairy', match: /dairy|breakfast/i, art: 'Dairy, Bread & Eggs' },
  { label: 'Bakery', match: /bakery|bread|breakfast/i, sub: /bread|bun/i, art: 'Dairy, Bread & Eggs', photo: 'Bread & Buns' },
  { label: 'Household', match: /household|cleaning|^home$/i, art: 'Cleaning & Household Essentials' },
  { label: 'More', match: /personal|beauty/i, art: 'Personal Care & Hygiene' },
];

const storeDepartments = [
  { name: 'Fresh produce', caption: 'Vegetables & fruits', icon: 'leaf', color: 'green', category: 1 },
  { name: 'Neighbourhood kirana', caption: 'Grocery & daily needs', icon: 'store', color: 'red', category: 0 },
  { name: 'Sweets & treats', caption: 'Something to celebrate', icon: 'sweet', color: 'maroon', category: 3 },
  { name: 'Dairy shops', caption: 'Fresh dairy essentials', icon: 'milk', color: 'cyan', category: 4 },
  { name: 'Household stores', caption: 'Everyday home essentials', icon: 'cart', color: 'blue', category: 6 },
  { name: 'Local bakeries', caption: 'Bread & breakfast', icon: 'bread', color: 'brown', category: 5 },
];

export default function MarketplaceHome({ managedCategories, subCategories, products, Quantity, chooseFilter }) {
  const router = useRouter();
  const browse = item => {
    const parent = managedCategories.find(category => item.match.test(category.name));
    const sub = parent && item.sub && subCategories.find(category => Number(category.category_id) === Number(parent.id) && item.sub.test(category.name));
    if (sub) router.push(`/category/${encodeURIComponent(parent.name.toLowerCase())}/${sub.id}`, { scroll: false });
    else chooseFilter(parent?.name || 'All');
  };
  return <div className="marketplace-home">
    <section className="market-hero" aria-labelledby="market-hero-title">
      <img className="market-hero-background" width="2172" height="724" src="/assets/dehradun-delivery-hero.png" alt="A Dropbell delivery partner overlooking Dehradun and the Himalayas" fetchPriority="high" />
      <div className="market-hero-inner"><div className="market-hero-copy"><MarketBrand hero />
        <h1 id="market-hero-title"><strong>Dehradun</strong> ka apna <strong>Online Marketplace</strong></h1>
        <p>Groceries, Fruits, Vegetables, Sweets, Dairy,<br className="desktop-break" /> Bakery, Household & More...</p>
        <div className="market-hero-benefits"><div><MarketIcon name="shield" /><span><b>Fresh & Quality</b>Products</span></div><div><MarketIcon name="truck" /><span><b>Fast & Safe</b>Delivery</span></div><div><MarketIcon name="rupee" /><span><b>Everyday Prices</b>Local Stores</span></div></div>
      </div><p className="market-hero-hindi" lang="hi">देहरादून की<br />हर ज़रूरत<br />अब ऑनलाइन</p></div>
    </section>

    <div className="market-content">
      <section className="market-categories" id="categories" aria-labelledby="market-categories-title"><div className="market-section-heading"><h2 id="market-categories-title">What would you like to order today?</h2><button onClick={() => chooseFilter('All')}>View All Categories <MarketIcon name="arrow" /></button></div><div className="market-category-grid">{shoppingCategories.map(item => <button key={item.label} className="market-category-card" onClick={() => browse(item)}><span className="market-category-frame"><span className="market-category-art" style={item.photo ? subCategoryImageStyle(item.art, item.photo) : categoryImageStyle(item.art)} aria-hidden="true" /></span><b>{item.label}</b></button>)}</div></section>

      <section className="market-stores" id="stores" aria-labelledby="market-stores-title"><div className="market-section-heading"><div><h2 id="market-stores-title">Local stores. Everyday favourites.</h2><p>Discover the essentials your neighbourhood has to offer.</p></div><button onClick={() => chooseFilter('All')}>Explore All <MarketIcon name="arrow" /></button></div><div className="market-store-grid">{storeDepartments.map(store => <button key={store.name} className="market-store-card" onClick={() => browse(shoppingCategories[store.category])}><span className={`market-store-emblem ${store.color}`}><MarketIcon name={store.icon} /></span><b>{store.name}</b><span>{store.caption}</span><small>Explore category <MarketIcon name="arrow" /></small></button>)}</div></section>

      <section className="market-promotions" id="offers" aria-label="Shop everyday essentials"><article className="market-promo market-promo-fresh"><div><h2>Fresh Vegetables<br />& Fruits</h2><p lang="hi">हर दिन ताज़ा, हर घर के लिए</p><button onClick={() => browse(shoppingCategories[1])}>Shop Now <MarketIcon name="arrow" /></button></div><div className="market-promo-art" style={categoryImageStyle('Fruits & Vegetables')} aria-hidden="true" /></article><article className="market-promo market-promo-grocery"><div><h2>Grocery & Daily Needs</h2><p lang="hi">आपकी रोज़मर्रा की सभी ज़रूरतें</p><button onClick={() => browse(shoppingCategories[0])}>Shop Now <MarketIcon name="arrow" /></button></div><div className="market-promo-art" style={categoryImageStyle('Atta, Rice & Pulses')} aria-hidden="true" /></article></section>

      {products.length > 0 && <section className="market-products" aria-labelledby="market-products-title"><div className="market-section-heading"><h2 id="market-products-title">Your everyday essentials</h2><button onClick={() => chooseFilter('All')}>View All Products <MarketIcon name="arrow" /></button></div><div className="product-grid">{products.slice(0, 5).map(product => <article className="product" key={product.id}><Link href={`/product/${product.id}`} className="product-photo" aria-label={`View ${product.name}`}>{product.photo ? <img src={product.photo} alt={product.name} loading="lazy" /> : <span className="emoji" aria-hidden="true">{product.emoji}</span>}</Link><div className="product-body"><small>{product.cat}</small><h3><Link href={`/product/${product.id}`}>{product.name}</Link></h3><p className="quantity">{product.qty}</p><div className="product-bottom"><strong>₹{product.price}</strong><Quantity product={product} /></div></div></article>)}</div></section>}

      <section className="market-trust" aria-label="The Dropbell promise"><div><MarketIcon name="pin" /><span><b>Local Stores</b><small>Support Local Business</small></span></div><div><MarketIcon name="shield" /><span><b>Shop with Confidence</b><small>Clear prices. Easy ordering.</small></span></div><div><MarketIcon name="truck" /><span><b>Doorstep Delivery</b><small>Your everyday, made easier</small></span></div><Link href="/help"><MarketIcon name="support" /><span><b>Here to Help</b><small>Find answers & support</small></span></Link></section>
    </div>
  </div>;
}
