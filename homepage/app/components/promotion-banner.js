'use client';

import SiteIcon from './site-icon';
import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { startPromotionPolling } from '../lib/promotion-polling.mjs';
import { sectionPromotions } from '../lib/shopping-sections.mjs';

export default function PromotionBanner({ section = 'groceries' }) {
  const [promotions, setPromotions] = useState([]);
  const [index, setIndex] = useState(0);
  const [dragOffset, setDragOffset] = useState(0);
  const [dragging, setDragging] = useState(false);
  const [interacting, setInteracting] = useState(false);
  const [reducedMotion, setReducedMotion] = useState(true);
  const [copied, setCopied] = useState('');
  const drag = useRef(null);
  const suppressClick = useRef(false);
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
    if (promotions.length < 2 || dragging || interacting || reducedMotion) return;
    const timer = setInterval(() => { if (!document.hidden) setIndex(current => (current + 1) % promotions.length); }, 6000);
    return () => clearInterval(timer);
  }, [promotions.length, dragging, interacting, reducedMotion]);
  if (!promotions.length) return null;
  const next = delta => { setIndex(current => (current + delta + promotions.length) % promotions.length); setCopied(''); };
  const promotion = promotions[index];
  if (!promotion) return null;
  const finishDrag = (event, cancelled = false) => {
    const gesture = drag.current;
    if (!gesture || gesture.id !== event.pointerId) return;
    drag.current = null;
    if (!cancelled && gesture.horizontal && Math.abs(gesture.distance) > 40) next(gesture.distance < 0 ? 1 : -1);
    setDragging(false);
    setDragOffset(0);
    if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
  };
  return <section id="offers" className="promotion-carousel" aria-label="Coupons and offers" aria-roledescription="carousel" onMouseEnter={() => setInteracting(true)} onMouseLeave={() => setInteracting(false)} onFocusCapture={() => setInteracting(true)} onBlurCapture={event => { if (!event.currentTarget.contains(event.relatedTarget)) setInteracting(false); }}>
    <div className={`promotion-viewport${dragging ? ' is-dragging' : ''}`} onDragStart={event => event.preventDefault()} onClickCapture={event => { if (suppressClick.current) { event.preventDefault(); event.stopPropagation(); suppressClick.current = false; } }} onPointerDown={event => {
      if (!event.isPrimary || event.button !== 0 || promotions.length < 2) return;
      suppressClick.current = false;
      drag.current = { id: event.pointerId, x: event.clientX, y: event.clientY, distance: 0, horizontal: false };
    }} onPointerMove={event => {
      const gesture = drag.current;
      if (!gesture || gesture.id !== event.pointerId) return;
      const distance = event.clientX - gesture.x;
      if (!gesture.horizontal) {
        if (Math.abs(event.clientY - gesture.y) > Math.max(8, Math.abs(distance))) { drag.current = null; return; }
        if (Math.abs(distance) < 8) return;
        gesture.horizontal = true;
        suppressClick.current = true;
        event.currentTarget.setPointerCapture(event.pointerId);
        setDragging(true);
      }
      gesture.distance = distance;
      setDragOffset(distance);
    }} onPointerUp={event => finishDrag(event)} onPointerCancel={event => finishDrag(event, true)} onLostPointerCapture={event => finishDrag(event, true)}><div className="promotion-track" style={{ transform: `translateX(calc(-${index * 100}% + ${dragOffset}px))`, ...(dragging ? { transition: 'none' } : {}) }}>
    {promotions.map((promotion, slideIndex) => <div className={`promotion-slide promotion-ad promotion-theme-${slideIndex % 3}${section === 'food' ? ' promotion-food' : ''}${promotion.code === 'THALI10' ? ' promotion-thali' : promotion.code === 'NORTH50' ? ' promotion-north-indian' : ''}`} key={promotion.id} role="group" aria-roledescription="slide" aria-hidden={slideIndex !== index} aria-label={`${slideIndex + 1} of ${promotions.length}`}>
      <span className="promotion-orbit" aria-hidden="true" /><span className="promotion-spark promotion-spark-one" aria-hidden="true">✦</span><span className="promotion-spark promotion-spark-two" aria-hidden="true">✧</span>
      <div className="promotion-copy">
        <span className="promotion-eyebrow">{promotion.code === 'THALI10' ? 'THE NORTH INDIAN THALI TABLE.' : promotion.code === 'NORTH50' ? 'CURRIES. NAAN. COMFORT.' : (section === 'food' ? ['BIG FLAVOUR. LITTLE PRICES.', 'MAKE IT A FEAST.', 'SWEET LITTLE SAVINGS.'] : ['FRESH FINDS. BIG SAVINGS.', 'YOUR EVERYDAY, FOR LESS.', 'A BAG FULL OF GOOD DEALS.'])[slideIndex % 3]}</span>
        {promotion.offer ? <h2 className="promotion-discount"><span className="promotion-discount-intro">{promotion.offer.kind === 'fixed' ? 'FLAT' : 'SAVE'}</span><span className="promotion-discount-value">{promotion.offer.value}<em>{promotion.offer.label}</em></span></h2> : <h2>{promotion.title}</h2>}
        {promotion.offer && <h3 className="promotion-tagline">{promotion.title}</h3>}
        {promotion.offer && <p className="promotion-qualifier">{promotion.offer.minimum}{promotion.offer.maximum ? ` · ${promotion.offer.maximum}` : ''}</p>}
        <div className="promotion-action-row">
          <Link className="promotion-shop" href={['THALI10','NORTH50'].includes(promotion.code) ? '/category/north%20indian' : '/category/all'} tabIndex={slideIndex === index ? 0 : -1}>{['THALI10','NORTH50'].includes(promotion.code) ? 'Explore North Indian' : section === 'food' ? 'Order food' : 'Shop now'} <span aria-hidden="true"><SiteIcon name="arrow-up-right" size={16} /></span></Link>
          {promotion.code && <button type="button" className="promotion-code" tabIndex={slideIndex === index ? 0 : -1} aria-label={`Copy coupon ${promotion.code}`} onClick={async () => { try { await navigator.clipboard.writeText(promotion.code); sessionStorage.setItem('dropbell-coupon', promotion.code); setCopied('Code copied. Apply it in your bag.'); } catch { setCopied(`Use code ${promotion.code} in your bag.`); } }}><span>USE CODE</span><b>{promotion.code}</b><span aria-hidden="true"><SiteIcon name="copy" size={16} /></span></button>}
        </div>
        {promotion.description && <p className="promotion-terms">{promotion.description}</p>}
        <span className="promotion-feedback" role="status">{slideIndex === index ? copied : ''}</span>
      </div>
      {promotion.image_url && <div className="promotion-artwork"><img src={promotion.image_url} alt="" className="promotion-image" loading={slideIndex === 0 ? 'eager' : 'lazy'} /></div>}
    </div>)}
    </div></div>
  </section>;
}
