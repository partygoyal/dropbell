'use client';
import SiteIcon from './site-icon';
import {FavouriteButton,ProductReviews} from './discovery-features';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { useParams, useRouter, useSearchParams } from 'next/navigation';
import ProductImage from './product-image';

const departments = [
  ['All', 'All essentials', '✳'], ['Fresh', 'Fruits & vegetables', '🥬'],
  ['Breakfast', 'Dairy & breakfast', '🥛'], ['Snacks', 'Snacks & drinks', '🍿'], ['Home', 'Home & personal care', '⌂'],
];
const titles = Object.fromEntries(departments.map(([key, label]) => [key, label]));
const descriptions = {
  All: 'The little things that keep your day going. Find all your favourites in one place.',
  Fresh: 'Fresh colours, familiar favourites. A little goodness for every plate.',
  Breakfast: 'Make your mornings a little easier, from the first sip to the last bite.',
  Snacks: 'For movie nights, midday breaks, and just-one-more moments.',
  Home: 'A happy little restock for the place you call home.',
};

function ProductArt({ product, large = false }) {
  return <ProductImage key={product.id} product={product} large={large} />;
}

export function ProductCard({ product, Quantity }) {
  return <article className="product"><Link href={`/product/${product.id}`} className="product-photo" aria-label={`View ${product.name}`}><ProductArt product={product} />{product.badge && <span className="badge">{product.badge}</span>}</Link><div className="product-body"><small>{titles[product.cat] || product.cat}</small><h3><Link href={`/product/${product.id}`}>{product.name}</Link></h3><p className="quantity">{product.qty}</p>{product.section==='food'&&<p className="dietary-label">{product.dietaryLabel==='non_vegetarian'?'Non-vegetarian':product.dietaryLabel==='vegetarian'?'Vegetarian':product.dietaryLabel==='vegan'?'Vegan':'Diet not specified'}</p>}{product.reviewCount>0&&<small><SiteIcon name="star" size={14} /> {product.rating}/5 · {product.reviewCount} reviews</small>}<p className="product-stock">{product.sample ? `Sample price · ${product.inventory} sample packs` : product.inventory == null ? 'Stock confirmed at checkout' : `${product.inventory} packs available`}</p><div className="product-bottom"><strong>₹{product.price}</strong><Quantity product={product} /></div></div></article>;
}

export function CatalogView({ managedCategories, subCategories, category, products, query, setQuery, chooseFilter, Quantity }) {
  const searchParams = useSearchParams();
  const initialQuery = searchParams.get('q') || '';
  const [sort, setSort] = useState('featured');
  const [budget, setBudget] = useState(false);
  const router = useRouter();
  const { subCategoryId } = useParams();
  const subCategory = typeof subCategoryId === 'string' ? subCategoryId : '';
  const productsOnly = !!subCategory || !!initialQuery.trim();
  const categoryUrl = `/category/${encodeURIComponent(category.toLowerCase())}`;
  const setSubCategory = id => { setBudget(false); setQuery(''); router.push(id ? `${categoryUrl}/${id}` : categoryUrl, { scroll: false }); };
  const departments = [['All', 'All essentials', '✳'], ...managedCategories.map(item => [item.name, titles[item.name] || item.name, '◈'])];
  const parentId = managedCategories.find(item => item.name.toLowerCase() === category.toLowerCase())?.id;
  const availableSubCategories = subCategories.filter(item => !parentId || Number(item.category_id) === Number(parentId));
  const parentCategory = managedCategories.find(item => Number(item.id) === Number(parentId));
  const categoryTitle = titles[category] || managedCategories.find(item => item.name.toLowerCase() === category.toLowerCase())?.name || category;
  const selectedSubCategory = availableSubCategories.find(item => String(item.id) === subCategory);
  useEffect(() => {
    if (!productsOnly) return;
    const frame = requestAnimationFrame(() => document.getElementById('category-products')?.scrollIntoView({ block: 'start', behavior: 'instant' }));
    return () => cancelAnimationFrame(frame);
  }, [subCategory, productsOnly]);
  useEffect(() => { setQuery(initialQuery); }, [initialQuery, category, setQuery]);
  const matching = products.filter(product => (category === 'All' || product.cat.toLowerCase() === category.toLowerCase()) && (!subCategory || String(product.subCategoryId) === subCategory) && (!budget || product.price <= 50) && `${product.name} ${titles[product.cat] || product.cat}`.toLowerCase().includes(query.toLowerCase().trim()));
  const sorted = [...matching].sort((a, b) => sort === 'low' ? a.price - b.price : sort === 'high' ? b.price - a.price : sort === 'name' ? a.name.localeCompare(b.name) : a.id - b.id);
  const clear = () => { setQuery(''); setBudget(false); setSort('featured'); };

  return <div className="catalog-page"><div className="breadcrumbs"><Link href="/">Home</Link><span>/</span><Link href={categoryUrl} scroll={false}>{categoryTitle}</Link>{subCategory && <><span>/</span><span>{selectedSubCategory?.name || 'Products'}</span></>}</div><div className="catalog-banner"><div><p className="eyebrow">YOUR EVERYDAY, ALL TOGETHER</p><h1>{selectedSubCategory?.name || categoryTitle}</h1><p>{descriptions[category] || 'Explore everyday essentials in this category.'}</p></div><span className="catalog-flower" aria-hidden="true">✳</span></div><div className="catalog-layout"><aside className="catalog-sidebar"><p className="eyebrow">SHOP BY DEPARTMENT</p>{departments.map(([value, label, icon]) => <button key={value} className={value.toLowerCase() === category.toLowerCase() ? 'selected' : ''} aria-pressed={value.toLowerCase() === category.toLowerCase()} onClick={() => chooseFilter(value)}><span aria-hidden="true">{icon}</span>{label}<span className="department-count">{value === 'All' ? products.length : products.filter(product => product.cat === value).length}</span></button>)}<div className="sidebar-note"><b>A little less running around.</b><p>Everything on your list, a little closer to home.</p></div></aside><div className="catalog-results">
      {!productsOnly && parentCategory && availableSubCategories.length > 0 && <nav className="catalog-category-links" aria-label="Filter by sub-category"><Link className="active" href={categoryUrl}>All {categoryTitle}</Link>{availableSubCategories.map(item => <Link key={item.id} href={`${categoryUrl}/${item.id}`} scroll={false}>{item.name}</Link>)}</nav>}
      {!productsOnly && !parentCategory && managedCategories.length > 0 && <nav className="catalog-category-links" aria-label="Filter by category"><button type="button" className="active" onClick={() => chooseFilter('All')}>All products</button>{managedCategories.map(item => <button type="button" key={item.id} onClick={() => chooseFilter(item.name)}>{item.name}</button>)}</nav>}
      <div className="catalog-listing"><div className="listing-toolbar" id="category-products"><div>{productsOnly && <Link className="subcategory-back" href={categoryUrl} scroll={false}><SiteIcon name="arrow-left" size={16} /> All sub-categories</Link>}<h2>{query.trim() ? `Results for “${query.trim()}”` : (selectedSubCategory?.name ? `${selectedSubCategory.name} products` : category === 'All' ? 'All products' : `${categoryTitle} products`)}</h2><p role="status">{sorted.length} {sorted.length === 1 ? 'essential' : 'essentials'} to explore</p></div><label className="sort-label">Sort by <select value={sort} onChange={event => setSort(event.target.value)}><option value="featured">Featured</option><option value="low">Price: low to high</option><option value="high">Price: high to low</option><option value="name">Name: A–Z</option></select></label></div><div className="listing-filters"><label>Sub-category <select value={subCategory} onChange={event => setSubCategory(event.target.value)}><option value="">All sub-categories</option>{availableSubCategories.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label><input type="checkbox" checked={budget} onChange={event => setBudget(event.target.checked)} /> ₹50 and under</label>{(budget || query) && <button onClick={clear}>Clear filters ×</button>}</div>{sorted.length ? <div className="product-grid catalog-products">{sorted.map(product => <ProductCard key={product.id} product={product} Quantity={Quantity} />)}</div> : <div className="empty-results"><span aria-hidden="true">⌕</span><h2>No essentials found</h2><p>{!products.some(product => category === 'All' || product.cat.toLowerCase() === category.toLowerCase()) ? 'Products will appear when a local vendor makes them available for your delivery area.' : 'Try another sub-category or clear your filters.'}</p><button className="primary" onClick={clear}>Clear search & filters</button></div>}<p className="catalog-disclaimer">Catalogue and prices are managed by Dropbell.</p></div></div></div></div>;
}

export function ProductView({ product, products, Quantity }) {
  if (!product) return null;
  const related = products.filter(item => item.id !== product.id && item.cat === product.cat);
  const suggestions = related.length ? related : products.filter(item => item.id !== product.id).slice(0, 4);
  return <div className="detail-page"><FavouriteButton id={product.id}/><div className="breadcrumbs"><Link href="/">Home</Link><span>/</span><Link href={`/category/${encodeURIComponent(product.cat.toLowerCase())}`}>{titles[product.cat] || product.cat}</Link><span>/</span><span>{product.name}</span></div><div className="dietary-information"><p>Diet: {product.dietaryLabel?.replaceAll('_',' ')||'Not specified'}</p><p>Allergens reported by the shop: {product.allergens?.length?product.allergens.join(', '):'Not specified'}. Ask the kitchen about allergies and cross-contact.</p></div><div className="detail-layout"><div className="detail-gallery"><div className="detail-image"><ProductArt product={product} large />{product.badge && <span className="detail-badge">{product.badge}</span>}</div><p>{product.photo?.includes('/food-demo-dishes/') ? <>Real dish photograph for this demo menu. <a href={`/food-photo-credits.html#${product.photo.split('/').pop().replace('.webp', '')}`}>Photo credit</a></> : 'Images are illustrative. Actual packaging may vary.'}</p></div><div className="detail-summary"><Link className="eyebrow" href={`/category/${encodeURIComponent(product.cat.toLowerCase())}`}>{titles[product.cat] || product.cat}</Link><h1>{product.name}</h1><p className="detail-description">{(product.description || 'An everyday essential from the Dropbell catalogue.')}</p><div className="pack-selection"><h3>Pack size</h3><div className="pack-option"><span>{product.qty}</span><strong>₹{product.price}</strong><span className="pack-check" aria-label="Selected pack"><SiteIcon name="check" size={16} /></span></div></div><div className="detail-purchase"><div><strong>₹{product.price}</strong><small>Inclusive of applicable taxes</small></div><Quantity product={product} /></div><div className="detail-benefits"><div><span><SiteIcon name="home" size={16} /></span><div><b>A little closer to home</b><p>Choose your location above to set your delivery area.</p></div></div><div><span><SiteIcon name="heart" size={16} /></span><div><b>Everyday, made easier</b><p>Add to your bag and keep exploring your favourites.</p></div></div></div><p className="detail-preview-note">{product.sample ? `Sample price and ${product.inventory} illustrative packs. Checkout available as a test Cash on Delivery order.` : product.inventory == null ? 'Availability is updated by our local vendors. Stock is confirmed at checkout.' : `${product.inventory} packs available. Sign in to place your order.`}</p></div></div><section className="product-information"><div><p className="eyebrow">A CLOSER LOOK</p><h2>Good to know</h2></div><div><details open><summary>About this essential</summary><p>{(product.description || 'An everyday essential from the Dropbell catalogue.')}</p><dl><div><dt>Pack size</dt><dd>{product.qty}</dd></div><div><dt>Department</dt><dd>{titles[product.cat] || product.cat}</dd></div></dl></details><details><summary>Storage & care</summary><p>{(product.care || 'Refer to the product packaging for storage and care instructions.')}</p></details><details><summary>Product information</summary><p>Confirm ingredients, allergens, manufacturer details, and expiry information on the actual product label before use.</p></details></div></section><ProductReviews id={product.id}/><section><div className="section-heading"><div><p className="eyebrow">ROUND OUT YOUR LITTLE HAUL</p><h2>You might also need</h2></div><Link className="text-button" href={`/category/${encodeURIComponent(product.cat.toLowerCase())}`}>Explore department <SiteIcon name="arrow-up-right" size={16} /></Link></div><div className="product-grid">{suggestions.map(item => <ProductCard key={item.id} product={item} Quantity={Quantity} />)}</div></section></div>;
}
