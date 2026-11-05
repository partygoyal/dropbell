'use client';

import SiteIcon from './site-icon';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { startPromotionPolling } from '../lib/promotion-polling.mjs';
import { sectionPromotions } from '../lib/shopping-sections.mjs';

const themes = ['sunshine', 'cherry', 'mint'];
const headlines = ['A little treat.\nA lovely saving.', 'Big cravings.\nLittle prices.', 'Good things.\nGreat deals.'];

export default function OfferSidebar({ section = 'food' }) {
  const [offers, setOffers] = useState([]);
  const [message, setMessage] = useState('');
  useEffect(() => startPromotionPolling(rows => setOffers(sectionPromotions(rows, section).slice(0, 3))), [section]);
  return <aside className="offer-sidebar" aria-label="Offers and featured picks"><div className="offer-sidebar-heading"><span aria-hidden="true">✦</span><h2>A little extra for you</h2></div><div className="offer-sidebar-stack">
    {offers.length ? offers.map((offer, index) => <article className={`sidebar-creative sidebar-creative-${themes[index]}`} key={offer.id}>
      <span className="sidebar-ad-label">DROPBELL OFFERS <span aria-hidden="true">✦</span></span>
      <h3>{headlines[index]}</h3>
      {offer.offer && <div className="sidebar-ad-discount"><strong>{offer.offer.value}</strong><b>{offer.offer.label || 'OFF'}</b></div>}
      <div className="sidebar-ad-art" aria-hidden="true">{offer.image_url ? <img src={offer.image_url} alt="" loading="lazy" /> : <span>{section === 'food' ? '♨' : '✳'}</span>}</div>
      <p className="sidebar-ad-title">{offer.title}</p>
      <p className="sidebar-ad-terms">{offer.description}</p>
      {offer.code && <button type="button" className="sidebar-ad-code" aria-label={`Copy coupon ${offer.code}`} onClick={async () => { try { await navigator.clipboard.writeText(offer.code); setMessage(`${offer.code} copied. Apply it at checkout.`); } catch { setMessage(`Use ${offer.code} at checkout.`); } }}>{message.startsWith(`${offer.code} copied`) ? 'Copied' : 'Use'} <b>{offer.code}</b><span aria-hidden="true"><SiteIcon name="copy" size={16} /></span></button>}
      <Link className="sidebar-ad-link" href={['THALI10','NORTH50'].includes(offer.code) ? '/category/north%20indian' : section === 'food' ? '/category/all' : '/groceries#products'}>Explore & save <span aria-hidden="true"><SiteIcon name="arrow-up-right" size={16} /></span></Link>
    </article>) : [
      ['sunshine', 'Fresh flavours.\nLocal favourites.', 'Discover something delicious from kitchens around you.', section === 'food' ? '/category/north%20indian' : '/category/fruits%20%26%20vegetables', 'Explore local picks', '✦'],
      ['cherry', 'Your next\nhappy bite.', 'Find a new favourite for your next doorstep delivery.', section === 'food' ? '/category/south%20indian' : '/category/all', 'Find your favourites', '♡'],
      ['mint', 'Good food.\nGood company.', 'Bring a little more variety to your everyday.', section === 'food' ? '/category/chinese' : '/groceries#products', 'Start exploring', '✳'],
    ].map(([theme,title,description,href,cta,art]) => <article key={theme} className={`sidebar-creative sidebar-creative-${theme} sidebar-creative-discovery`}><span className="sidebar-ad-label">DISCOVER DROPBELL</span><h3>{title}</h3><span className="sidebar-discovery-art" aria-hidden="true">{art}</span><p>{description}</p><Link className="sidebar-ad-link" href={href}>{cta} ↗</Link></article>)}
    </div><p className="sidebar-copy-status" role="status" aria-live="polite">{message}</p></aside>;
}
