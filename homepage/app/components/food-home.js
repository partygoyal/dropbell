'use client';

import Link from 'next/link';
import { useState } from 'react';
import { ProductCard } from './shopping-views';
import { homeProductGroups } from '../lib/home-products.mjs';

export default function FoodHome({ products, vendors, categories, Quantity, hasLocation, ready, chooseFilter }) {
  const [search, setSearch] = useState('');
  const [allCategories, setAllCategories] = useState(false);
  const [restaurantSort, setRestaurantSort] = useState('default');
  const term = search.trim().toLowerCase();
  const restaurants = vendors.filter(item => `${item.name} ${item.city} ${(item.cuisines || []).join(' ')}`.toLowerCase().includes(term));
  if (restaurantSort === 'name') restaurants.sort((a, b) => a.name.localeCompare(b.name));
  if (restaurantSort === 'menu') restaurants.sort((a, b) => Number(b.product_count) - Number(a.product_count));
  const groups = homeProductGroups(products, { search }).slice(0, 3);
  return <div className="marketplace-home food-home"><div className="market-content">
    <section className="food-categories" aria-labelledby="food-categories-title"><div className="market-section-heading"><div><p className="eyebrow">GOOD FOOD. CLOSE TO HOME.</p><h1 id="food-categories-title">What are you craving?</h1><p>Find a flavour for every mood.</p></div>{categories.length > 8 && <button type="button" onClick={() => setAllCategories(value => !value)} aria-expanded={allCategories}>{allCategories ? 'Show less' : `View all ${categories.length}`} →</button>}</div><div className="food-category-grid">{categories.slice(0, allCategories ? categories.length : 8).map(item => <button type="button" key={item.id} className="food-category-card" onClick={() => chooseFilter(item.name)}><span className="food-category-art">{item.image_url ? <img src={item.image_url} alt="" loading="lazy" /> : <span aria-hidden="true">♨</span>}</span><b>{item.name}</b></button>)}</div></section>
    <section className="food-discovery" aria-labelledby="restaurants-title"><div className="market-section-heading"><div><h2 id="restaurants-title">Restaurants near you</h2><p>{hasLocation ? 'Menus from kitchens serving your selected neighbourhood.' : 'Choose your delivery location in the header to find restaurants nearby.'}</p></div><Link href="/shops">View restaurants →</Link></div>
      <div className="food-restaurant-filters"><label className="food-search">Find a restaurant or dish<input type="search" value={search} onChange={event => setSearch(event.target.value)} placeholder="Search restaurants, biryani, pizza…" /></label><label className="food-sort">Sort restaurants<select value={restaurantSort} onChange={event => setRestaurantSort(event.target.value)}><option value="default">Default order</option><option value="name">Name: A–Z</option><option value="menu">Most menu options</option></select></label></div>
      {!ready ? <p role="status">Finding restaurants and menus…</p> : !hasLocation ? <div className="food-empty"><span aria-hidden="true">⌖</span><h3>Your next meal starts nearby.</h3><p>Set your location above to see restaurants that deliver to you.</p></div> : restaurants.length ? <div className="market-store-grid">{restaurants.map(item => <Link className="market-store-card restaurant-card" key={item.id} href={`/shops/${item.id}`}>{item.image_url ? <img className="restaurant-cover" src={item.image_url} alt={`${item.name} menu`} loading="lazy" /> : <span className="restaurant-emblem" aria-hidden="true">♨</span>}<b>{item.name}</b><span>{(item.cuisines || []).join(' · ') || item.city}</span><span>{item.city}</span><small>{item.product_count} menu items · View menu →</small></Link>)}</div> : <div className="food-empty"><span aria-hidden="true">♨</span><h3>{term ? 'No restaurants match your search.' : 'No restaurants available here yet.'}</h3><p>{term ? 'Try another restaurant name or browse the dishes below.' : 'Restaurant menus will appear here when kitchens start serving your neighbourhood.'}</p>{term && <button className="secondary" type="button" onClick={() => setSearch('')}>Clear search</button>}</div>}
    </section>
    {!!groups.length && <section className="food-menu-discovery" aria-label="Explore food menus"><div className="market-section-heading"><h2>Fresh from the menu</h2><button type="button" onClick={() => chooseFilter('All')}>Explore all dishes →</button></div>{groups.map(group => <section className="home-category-section" key={group.name}><div className="market-section-heading"><h3>{group.name}</h3><button type="button" onClick={() => chooseFilter(group.name)}>View dishes →</button></div><div className="home-product-row">{group.products.slice(0, 6).map(product => <ProductCard key={product.id} product={product} Quantity={Quantity} />)}</div></section>)}</section>}
    <section className="restaurant-partner-callout"><div><h2>Bring your kitchen to the neighbourhood.</h2><p>Partner with Dropbell and let customers discover your restaurant.</p></div><a href="/vendor/register" className="secondary">List your restaurant →</a></section>
  </div></div>;
}
