'use client';

import { useEffect, useRef, useState } from 'react';
import { fetchJson } from '../lib/http.mjs';

export default function PromotionBanner() {
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
  useEffect(() => {
    const controller = new AbortController();
    const load = () => fetchJson('/api/promotions', { signal: controller.signal }).then(body => {
      setPromotions(Array.isArray(body.data) ? body.data : []); setIndex(current => current < body.data?.length ? current : 0);
    }).catch(() => {});
    load(); const refresh = setInterval(load, 60000);
    return () => { clearInterval(refresh); controller.abort(); };
  }, []);
  useEffect(() => {
    if (promotions.length < 2 || paused || interacting || reducedMotion) return;
    const timer = setInterval(() => { if (!document.hidden) setIndex(current => (current + 1) % promotions.length); }, 6000);
    return () => clearInterval(timer);
  }, [promotions.length, paused, interacting, reducedMotion]);
  if (!promotions.length) return null;
  const next = delta => { setIndex(current => (current + delta + promotions.length) % promotions.length); setCopied(''); };
  const promotion = promotions[index];
  if (!promotion) return null;
  return <section className="promotion-carousel" aria-label="Coupons and offers" aria-roledescription="carousel" onMouseEnter={() => setInteracting(true)} onMouseLeave={() => setInteracting(false)} onFocusCapture={() => setInteracting(true)} onBlurCapture={event => { if (!event.currentTarget.contains(event.relatedTarget)) setInteracting(false); }} onTouchStart={event => { touch.current = event.touches[0].clientX; }} onTouchEnd={event => { if (touch.current !== null) { const distance = event.changedTouches[0].clientX - touch.current; if (Math.abs(distance) > 40) next(distance < 0 ? 1 : -1); touch.current = null; } }}>
    <div className="promotion-slide" role="group" aria-roledescription="slide" aria-label={`${index + 1} of ${promotions.length}`}>
      <div className="promotion-copy"><span className="promotion-eyebrow">A LITTLE EXTRA SAVING</span><h2>{promotion.title}</h2>{promotion.description && <p>{promotion.description}</p>}{promotion.code && <button type="button" className="promotion-code" onClick={async () => { try { await navigator.clipboard.writeText(promotion.code); sessionStorage.setItem('dropbell-coupon', promotion.code); setCopied('Code copied. Apply it in your bag.'); } catch { setCopied(`Use code ${promotion.code} in your bag.`); } }}>Use code <b>{promotion.code}</b> · Copy</button>}<span className="promotion-feedback" role="status">{copied}</span></div>
      {promotion.image_url && <img src={promotion.image_url} alt="" className="promotion-image" loading="lazy" />}
    </div>
    {promotions.length > 1 && <div className="promotion-controls"><button type="button" onClick={() => next(-1)} aria-label="Previous offer">←</button><div className="promotion-dots">{promotions.map((item, number) => <button key={item.id} type="button" aria-label={`Show offer ${number + 1}: ${item.title}`} aria-pressed={number === index} onClick={() => { setIndex(number); setCopied(''); }} />)}</div><button type="button" onClick={() => next(1)} aria-label="Next offer">→</button>{!reducedMotion && <button type="button" onClick={() => setPaused(value => !value)} aria-label={paused ? 'Resume automatic offers' : 'Pause automatic offers'}>{paused ? 'Play' : 'Pause'}</button>}</div>}
  </section>;
}
