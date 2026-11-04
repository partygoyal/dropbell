'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { startPromotionPolling } from '../lib/promotion-polling.mjs';
import { sectionPromotions } from '../lib/shopping-sections.mjs';

export default function PromotionBanner({ section = 'groceries' }) {
  const [promotions, setPromotions] = useState([]);
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  const [interacting, setInteracting] = useState(false);
  const [reducedMotion, setReducedMotion] = useState(true);
  const [copied, setCopied] = useState('');
  const touch = useRef(null);
  useEffect(() => {
    const media = window.matchMedia('(prefers-reduced-motion: reduce)');
    const update = () => setReducedMotion(media.matches);
    update(); media.addEventListener('change', update);
    return () => media.removeEventListener('change', update);
  }, []);
  useEffect(() => startPromotionPolling(items => {
    const selected = sectionPromotions(items, section);
    setPromotions(selected);
    setIndex(current => current < selected.length ? current : 0);
  }), [section]);
  useEffect(() => {
    if (promotions.length < 2 || paused || interacting || reducedMotion) return;
    const timer = setInterval(() => { if (!document.hidden) setIndex(current => (current + 1) % promotions.length); }, 6000);
    return () => clearInterval(timer);
  }, [promotions.length, paused, interacting, reducedMotion]);
  if (!promotions.length) return null;
  const next = delta => { setIndex(current => (current + delta + promotions.length) % promotions.length); setCopied(''); };
  const promotion = promotions[index];
  if (!promotion) return null;
  return <section id="offers" className="promotion-carousel" aria-label="Coupons and offers" aria-roledescription="carousel" onMouseEnter={() => setInteracting(true)} onMouseLeave={() => setInteracting(false)} onFocusCapture={() => setInteracting(true)} onBlurCapture={event => { if (!event.currentTarget.contains(event.relatedTarget)) setInteracting(false); }} onTouchStart={event => { touch.current = event.touches[0].clientX; }} onTouchEnd={event => { if (touch.current !== null) { const distance = event.changedTouches[0].clientX - touch.current; if (Math.abs(distance) > 40) next(distance < 0 ? 1 : -1); touch.current = null; } }}>
    <div className="promotion-viewport"><div className="promotion-track" style={{ transform: `translateX(-${index * 100}%)` }}>
    {promotions.map((promotion, slideIndex) => <div className={`promotion-slide promotion-ad promotion-theme-${slideIndex % 3}${section === 'food' ? ' promotion-food' : ''}`} key={promotion.id} role="group" aria-roledescription="slide" aria-hidden={slideIndex !== index} aria-label={`${slideIndex + 1} of ${promotions.length}`}>
      <span className="promotion-orbit" aria-hidden="true" /><span className="promotion-spark promotion-spark-one" aria-hidden="true">✦</span><span className="promotion-spark promotion-spark-two" aria-hidden="true">✧</span>
      <div className="promotion-copy">
        <span className="promotion-eyebrow">{(section === 'food' ? ['BIG FLAVOUR. LITTLE PRICES.', 'MAKE IT A FEAST.', 'SWEET LITTLE SAVINGS.'] : ['FRESH FINDS. BIG SAVINGS.', 'YOUR EVERYDAY, FOR LESS.', 'A BAG FULL OF GOOD DEALS.'])[slideIndex % 3]}</span>
        {promotion.offer ? <h2 className="promotion-discount"><span className="promotion-discount-intro">{promotion.offer.kind === 'fixed' ? 'FLAT' : 'SAVE'}</span><span className="promotion-discount-value">{promotion.offer.value}<em>{promotion.offer.label}</em></span></h2> : <h2>{promotion.title}</h2>}
        {promotion.offer && <h3 className="promotion-tagline">{promotion.title}</h3>}
        {promotion.offer && <p className="promotion-qualifier">{promotion.offer.minimum}{promotion.offer.maximum ? ` · ${promotion.offer.maximum}` : ''}</p>}
        <div className="promotion-action-row">
          <Link className="promotion-shop" href="/category/all" tabIndex={slideIndex === index ? 0 : -1}>{section === 'food' ? 'Order food' : 'Shop now'} <span aria-hidden="true">↗</span></Link>
          {promotion.code && <button type="button" className="promotion-code" tabIndex={slideIndex === index ? 0 : -1} aria-label={`Copy coupon ${promotion.code}`} onClick={async () => { try { await navigator.clipboard.writeText(promotion.code); sessionStorage.setItem('dropbell-coupon', promotion.code); setCopied('Code copied. Apply it in your bag.'); } catch { setCopied(`Use code ${promotion.code} in your bag.`); } }}><span>USE CODE</span><b>{promotion.code}</b><span aria-hidden="true">⧉</span></button>}
        </div>
        {promotion.description && <p className="promotion-terms">{promotion.description}</p>}
        <span className="promotion-feedback" role="status">{slideIndex === index ? copied : ''}</span>
      </div>
      {promotion.image_url && <div className="promotion-artwork"><img src={promotion.image_url} alt="" className="promotion-image" loading={slideIndex === 0 ? 'eager' : 'lazy'} /></div>}
    </div>)}
    </div></div>
    {promotions.length > 1 && <div className="promotion-controls"><button type="button" onClick={() => next(-1)} aria-label="Previous offer">←</button><div className="promotion-dots">{promotions.map((item, number) => <button key={item.id} type="button" aria-label={`Show offer ${number + 1}: ${item.title}`} aria-pressed={number === index} onClick={() => { setIndex(number); setCopied(''); }} />)}</div><button type="button" onClick={() => next(1)} aria-label="Next offer">→</button>{!reducedMotion && <button type="button" onClick={() => setPaused(value => !value)} aria-label={paused ? 'Resume automatic offers' : 'Pause automatic offers'}>{paused ? 'Play' : 'Pause'}</button>}</div>}
  </section>;
}
