'use client';

import Link from 'next/link';
import { ProductCard } from './shopping-views';

export function ShopList({ vendors, hasLocation, onChooseLocation, ready }) {
  if (!ready) return <p role="status">Finding shops near you…</p>;
  if (!hasLocation) return <div className="shop-empty"><p>Choose your delivery location to discover shops serving your neighbourhood.</p><button className="primary" onClick={onChooseLocation}>Choose delivery location</button></div>;
  if (!vendors.length) return <div className="shop-empty"><p>No shops have available products for this location yet. Try another delivery locality or check again later.</p><button className="secondary" onClick={onChooseLocation}>Change location</button></div>;
  return <div className="market-store-grid">{vendors.map(vendor => <Link key={vendor.id} href={`/shops/${vendor.id}`} className="market-store-card"><span className="market-store-emblem blue" aria-hidden="true">▧</span><b>{vendor.name}</b><span>{vendor.city}</span><small>{vendor.product_count} available {Number(vendor.product_count) === 1 ? 'product' : 'products'} · View shop →</small></Link>)}</div>;
}

export default function ShopBrowser({ vendorId, vendors, activeVendorId, products, Quantity, hasLocation, onChooseLocation, onChooseVendor, ready, query, setQuery }) {
  const vendor = vendors.find(item => Number(item.id) === vendorId);
  if (!vendorId) return <div className="shop-page"><div className="breadcrumbs"><Link href="/">Home</Link><span>/</span><span>Local shops</span></div><p className="eyebrow">YOUR NEIGHBOURHOOD SHOPS</p><h1>Shop local, delivered.</h1><p>Browse available products from shops serving your selected delivery location.</p><ShopList {...{ vendors, hasLocation, onChooseLocation, ready }} /></div>;
  if (!hasLocation) return <div className="shop-page"><h1>Find this shop’s delivery area.</h1><ShopList {...{ vendors: [], hasLocation, onChooseLocation, ready }} /></div>;
  if (!ready) return <div className="shop-page" role="status">Loading this shop…</div>;
  if (!vendor) return <div className="shop-page"><h1>This shop is currently unavailable.</h1><p>It may not serve your selected location or may have no available products.</p><Link className="primary" href="/shops">Browse local shops</Link></div>;
  const matching = products.filter(product => `${product.name} ${product.cat}`.toLowerCase().includes(query.trim().toLowerCase()));
  return <div className="shop-page"><div className="breadcrumbs"><Link href="/">Home</Link><span>/</span><Link href="/shops">Local shops</Link><span>/</span><span>{vendor.name}</span></div><div className="shop-heading"><p className="eyebrow">YOUR LOCAL SHOP</p><h1>{vendor.name}</h1><p>{vendor.city} · {vendor.product_count} available products for your delivery location</p></div>{activeVendorId !== vendorId ? <section className="shop-empty"><h2>Shop from {vendor.name}</h2><p>View this shop’s products and prices. Your order will be fulfilled by this shop.</p><button className="primary" onClick={() => onChooseVendor(vendorId)}>Browse this shop’s products</button></section> : <><label className="shop-search">Search this shop<input type="search" value={query} onChange={event => setQuery(event.target.value)} placeholder="Search available products" /></label><p role="status">{matching.length} available {matching.length === 1 ? 'product' : 'products'}</p>{matching.length ? <div className="product-grid">{matching.map(product => <ProductCard key={product.id} product={product} Quantity={Quantity} />)}</div> : <p>No available products match your search.</p>}</>}</div>;
}
