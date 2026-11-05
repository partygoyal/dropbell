'use client';

import SiteIcon from './site-icon';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { fetchJson } from '../lib/http.mjs';
import { filterOffers, offerDate } from '../lib/offer-filters.mjs';

const emptyFilters = { query: '', category: '', item: '', from: '', to: '' };
const formatDate = value => offerDate(value)?.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Kolkata' });

export default function OffersPage({ categories = [], products = [] }) {
  const [offers, setOffers] = useState([]);
  const [filters, setFilters] = useState(emptyFilters);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [feedback, setFeedback] = useState('');
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    let active = true;
    const load = async () => {
      try {
        const body = await fetchJson('/api/promotions?listing=all', { cache: 'no-store' });
        if (!Array.isArray(body.data)) throw new Error('Invalid offers');
        if (active) { setOffers(body.data); setError(''); }
      } catch { if (active) setError('Offers are temporarily unavailable. Please try again.'); }
      finally { if (active) setLoading(false); }
    };
    setLoading(true); load();
    const timer = setInterval(load, 60000);
    return () => { active = false; clearInterval(timer); };
  }, [attempt]);
  const update = (key, value) => { setFeedback(''); setFilters(current => ({ ...current, [key]: value, ...(key === 'category' ? { item: '' } : {}) })); };
  const invalidDates = filters.from && filters.to && filters.from > filters.to;
  const visible = invalidDates ? [] : filterOffers(offers, filters);
  const selectedCategory = categories.find(category => String(category.id) === filters.category);
  const items = products.filter(product => !selectedCategory || product.cat === selectedCategory.name);
  const copy = async code => {
    try { await navigator.clipboard.writeText(code); setFeedback(`${code} copied. Apply it in your shopping bag.`); }
    catch { setFeedback(`Copy is unavailable. Use code ${code} in your shopping bag.`); }
  };
  return <div className="offers-page">
    <nav aria-label="Breadcrumb"><Link href="/">Home</Link><span aria-hidden="true"> / </span>Offers</nav>
    <header className="offers-intro"><span>MORE TO LOVE. LESS TO SPEND.</span><h1>Good finds. Great offers.</h1><p>Explore current offers and copy a code for your next order.</p></header>
    <section className="offers-filters" aria-label="Filter offers">
      <label>Search offers<input type="search" placeholder="Offer name or coupon code" value={filters.query} onChange={e => update('query', e.target.value)} /></label>
      <label>Category<select value={filters.category} onChange={e => update('category', e.target.value)}><option value="">All categories</option>{categories.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}</select></label>
      <label>Item<select value={filters.item} onChange={e => update('item', e.target.value)}><option value="">All items</option>{items.map(product => <option key={product.id} value={product.id}>{product.name}</option>)}</select></label>
      <label>Valid from<input type="date" value={filters.from} onChange={e => update('from', e.target.value)} /></label>
      <label>Valid through<input type="date" min={filters.from || undefined} value={filters.to} onChange={e => update('to', e.target.value)} /></label>
      <button type="button" onClick={() => setFilters(emptyFilters)}>Clear filters</button>
    </section>
    <p className="offers-scope-note">Dates use Indian Standard Time. Offers valid during any part of your selected dates are included. Order-wide coupons apply across categories and items, so they remain visible when you filter.</p>
    {invalidDates && <p role="alert">Choose an end date on or after the start date.</p>}
    {error && <p role="alert">{error} <button type="button" onClick={() => setAttempt(value => value + 1)}>Retry</button></p>}
    <p role="status" className="offers-feedback">{feedback || (loading ? 'Loading offers…' : `${visible.length} offers found`)}</p>
    {!loading && !error && visible.length === 0 && <div className="offers-empty"><h2>No offers match these filters.</h2><p>Try different dates or clear your filters.</p></div>}
    <div className="offers-grid">{visible.map((offer, index) => <article className={`offer-card offer-card-${index % 3}`} key={offer.id}>
      <div className="offer-card-art">{offer.image_url && <img src={offer.image_url} alt="" loading="lazy" />}<span>{offer.offer ? `${offer.offer.value} ${offer.offer.label}` : 'SPECIAL OFFER'}</span></div>
      <div className="offer-card-body"><span className="offer-scope">{offer.scope === 'all_items' ? 'ORDER-WIDE OFFER · ALL ITEMS' : 'SELECTED ITEMS'}</span><h2>{offer.title}</h2><p>{offer.description}</p>{offer.offer && <p className="offer-conditions">{offer.offer.minimum}{offer.offer.maximum ? ` · ${offer.offer.maximum}` : ''}</p>}<p className="offer-validity">{offer.starts_at ? `From ${formatDate(offer.starts_at)}` : 'Available now'}{offer.ends_at ? ` · Ends ${formatDate(offer.ends_at)}` : ' · No fixed end date'}</p>{offer.code ? <div className="offer-copy-row"><code>{offer.code}</code><button type="button" aria-label={`Copy code ${offer.code}`} onClick={() => copy(offer.code)}>Copy code <SiteIcon name="copy" size={16} /></button></div> : <span>No code needed</span>}<small>One coupon per order. Eligibility and savings are checked at checkout.</small></div>
    </article>)}</div>
  </div>;
}
