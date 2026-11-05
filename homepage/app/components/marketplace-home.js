'use client';
import OfferSidebar from './offer-sidebar';
import SiteIcon from './site-icon';

import Link from 'next/link';
import { useMemo, useState } from 'react';
import { ProductCard } from './shopping-views';
import { homeProductGroups } from '../lib/home-products.mjs';
import { ShopList } from './shop-browser';
import { useRouter } from 'next/navigation';
import { categoryImageStyle, subCategoryImageStyle } from '../lib/category-images';

export function MarketIcon({ name, className = '' }) {
  return <SiteIcon name={name} className={`market-icon ${className}`} />;
}

export function MarketBrand({ hero = false, caption = true }) {
  return <span className={`market-brand${hero ? ' market-brand-hero' : ''}`}>
    <img className="approved-brand-logo" src="/assets/dropbell-logo-approved.svg" width="2010" height="550" alt="Dropbell" />
    {caption && <small>{hero ? 'Sab Kuch, Ab Aapke Ghar Tak' : 'Delivery Company Private Limited'}</small>}
  </span>;
}

const shoppingCategories = [
  { label: 'Groceries', match: /grocery|atta|rice|pulses/i, art: 'Atta, Rice & Pulses' },
  { label: 'Vegetables', match: /fruit|vegetable|fresh/i, sub: /fresh vegetables/i, art: 'Fruits & Vegetables', photo: 'Fresh Vegetables' },
  { label: 'Fruits', match: /fruit|vegetable|fresh/i, sub: /fresh fruits/i, art: 'Fruits & Vegetables', photo: 'Fresh Fruits' },
  { label: 'Sweets', match: /sweet|chocolate/i, fallback: /snack/i, art: 'Biscuits, Chocolates & Sweets' },
  { label: 'Dairy', match: /dairy|breakfast/i, art: 'Dairy, Bread & Eggs' },
  { label: 'Bakery', match: /bakery|bread|breakfast/i, sub: /bread|bun/i, art: 'Dairy, Bread & Eggs', photo: 'Bread & Buns' },
  { label: 'Household', match: /household|cleaning|^home$/i, art: 'Cleaning & Household Essentials' },
  { label: 'More', match: /^All$/, art: 'Personal Care & Hygiene' },
];

export default function MarketplaceHome({ managedCategories, subCategories, products, Quantity, chooseFilter, vendors = [], hasLocation, onChooseLocation, catalogueReady }) {
  const router = useRouter();
  const [homeCategory, setHomeCategory] = useState('All');
  const [homeSearch, setHomeSearch] = useState('');
  const [homeSort, setHomeSort] = useState('featured');
  const [homeAvailability, setHomeAvailability] = useState('all');
  const categoryNames = useMemo(() => [...new Set([...managedCategories.map(item => item.name), ...products.map(item => item.cat)])].filter(name => name !== 'All' && products.some(product => product.cat === name)), [managedCategories, products]);
  const groups = useMemo(() => homeProductGroups(products, { category: homeCategory, search: homeSearch, sort: homeSort, availability: homeAvailability }).slice(0, 3), [products, homeCategory, homeSearch, homeSort, homeAvailability]);
  const resetHomeFilters = () => { setHomeCategory('All'); setHomeSearch(''); setHomeSort('featured'); setHomeAvailability('all'); };
  const browse = item => {
    const parent = managedCategories.find(category => item.match.test(category.name))
      || (item.fallback && managedCategories.find(category => item.fallback.test(category.name)));
    const sub = parent && item.sub && subCategories.find(category => Number(category.category_id) === Number(parent.id) && item.sub.test(category.name));
    if (sub) router.push(`/category/${encodeURIComponent(parent.name.toLowerCase())}/${sub.id}`, { scroll: false });
    else chooseFilter(parent?.name || 'All');
  };
  return <div className="marketplace-home">
    <div className="market-content">
      <section className="market-categories" id="categories" aria-labelledby="market-categories-title"><div className="market-section-heading"><h1 id="market-categories-title">What would you like to order today?</h1><button onClick={() => chooseFilter('All')}>View All Categories <MarketIcon name="arrow" /></button></div><div className="market-category-grid">{shoppingCategories.map(item => <button key={item.label} className="market-category-card" onClick={() => browse(item)}><span className="market-category-frame"><span className="market-category-art" style={item.photo ? subCategoryImageStyle(item.art, item.photo) : categoryImageStyle(item.art)} aria-hidden="true" /></span><b>{item.label}</b></button>)}</div></section>

      {hasLocation && <section className="market-stores" id="stores" aria-labelledby="market-stores-title"><div className="market-section-heading"><div><h2 id="market-stores-title">Local stores. Everyday favourites.</h2><p>Shop from vendors serving your delivery location.</p></div><Link href="/shops">Explore all shops <SiteIcon name="arrow" size={16} /></Link></div><ShopList vendors={vendors.slice(0, 6)} hasLocation={hasLocation} onChooseLocation={onChooseLocation} ready={catalogueReady} /></section>}

      <div className="product-with-sidebar"><div className="product-sidebar-main"><section className="home-product-browser" id="products" aria-labelledby="home-products-title">
        <div className="market-section-heading"><div><p className="eyebrow">FIND YOUR EVERYDAY</p><h2 id="home-products-title">A little of everything you need.</h2><p>Browse by category or find a favourite in seconds.</p></div><button onClick={() => chooseFilter('All')}>Explore the full catalogue <MarketIcon name="arrow" /></button></div>
        <div className="home-product-filters">
          <div className="home-category-pills" role="group" aria-label="Filter products by category"><button type="button" aria-pressed={homeCategory === 'All'} onClick={() => setHomeCategory('All')}>All products</button>{categoryNames.map(name => <button type="button" key={name} aria-pressed={homeCategory === name} onClick={() => setHomeCategory(name)}>{name}</button>)}</div>
          <div className="home-filter-controls"><label className="home-filter-search">Search products<input type="search" value={homeSearch} onChange={event => setHomeSearch(event.target.value)} placeholder="Search milk, fruits, snacks…" /></label><label>Sort within categories<select value={homeSort} onChange={event => setHomeSort(event.target.value)}><option value="featured">Featured</option><option value="price-low">Price: low to high</option><option value="price-high">Price: high to low</option><option value="name">Name: A to Z</option></select></label><label>Availability<select value={homeAvailability} onChange={event => setHomeAvailability(event.target.value)}><option value="all">All products</option><option value="orderable">Real shop products</option></select></label><button type="button" className="home-filter-reset" onClick={resetHomeFilters}>Reset filters</button></div>
        </div>
        <p className="home-filter-count" role="status">{groups.reduce((total, group) => total + group.products.length, 0)} products across {groups.length} {groups.length === 1 ? 'category' : 'categories'}</p>
        {groups.map(group => <section className="home-category-section" key={group.name} aria-label={group.name}><div className="market-section-heading"><div><h3>{group.name}</h3><p>{group.products.length} everyday {group.products.length === 1 ? 'essential' : 'essentials'}</p></div><button type="button" onClick={() => chooseFilter(group.name)}>View category <MarketIcon name="arrow" /></button></div><div className="home-product-row">{group.products.slice(0, 6).map(product => <ProductCard key={product.id} product={product} Quantity={Quantity} />)}</div></section>)}
        {catalogueReady && !groups.length && <div className="home-products-empty"><h3>No products match these filters.</h3><p>Try a different category or search, or reset your filters.</p><button type="button" className="secondary" onClick={resetHomeFilters}>Show all products</button></div>}
      </section></div><OfferSidebar section="groceries"/></div>

      <section className="market-trust" aria-label="The Dropbell promise"><div><MarketIcon name="pin" /><span><b>Local Stores</b><small>Support Local Business</small></span></div><div><MarketIcon name="shield" /><span><b>Shop with Confidence</b><small>Clear prices. Easy ordering.</small></span></div><div><MarketIcon name="truck" /><span><b>Doorstep Delivery</b><small>Your everyday, made easier</small></span></div><Link href="/help"><MarketIcon name="support" /><span><b>Here to Help</b><small>Find answers & support</small></span></Link></section>
    </div>
  </div>;
}
