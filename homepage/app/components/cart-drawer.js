'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';

export default function CartDrawer({ bag, count, subtotal, Quantity, onClose }) {
  const dialogRef = useRef(null);
  const [closing, setClosing] = useState(false);
  const close = () => setClosing(true);
  useEffect(() => {
    const dialog = dialogRef.current;
    const previousOverflow = document.body.style.overflow;
    const previousPadding = document.body.style.paddingRight;
    const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
    const padding = parseFloat(getComputedStyle(document.body).paddingRight) || 0;
    document.body.style.overflow = 'hidden';
    if (scrollbarWidth) document.body.style.paddingRight = `${padding + scrollbarWidth}px`;
    dialog.showModal();
    return () => {
      dialog.close();
      document.body.style.overflow = previousOverflow;
      document.body.style.paddingRight = previousPadding;
    };
  }, []);
  useEffect(() => {
    if (!closing) return;
    const timer = setTimeout(onClose, 220);
    return () => clearTimeout(timer);
  }, [closing, onClose]);
  const backdropClick = event => {
    if (event.target !== event.currentTarget) return;
    const rect = event.currentTarget.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) close();
  };
  const money = value => new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 }).format(value);
  return <dialog ref={dialogRef} className={`cart-drawer${closing ? ' is-closing' : ''}`} aria-labelledby="cart-drawer-title" onCancel={event => { event.preventDefault(); close(); }} onClick={backdropClick}>
    <div className="cart-drawer-heading"><div><p className="eyebrow">YOUR LITTLE HAUL</p><h2 id="cart-drawer-title">Your shopping bag</h2><p role="status">{count} {count === 1 ? 'item' : 'items'} in your bag</p></div><button type="button" className="drawer-close" onClick={close} aria-label="Close shopping bag" autoFocus>×</button></div>
    <div className="cart-drawer-body">{bag.length ? bag.map(product => <div className="cart-row" key={product.id}><div><b>{product.name}</b><p>{product.qty} · {money(product.price)}</p></div><Quantity product={product} /></div>) : <div className="cart-drawer-empty"><h3>A little room for good things.</h3><p>Add your favourites to get started.</p></div>}</div>
    <div className="cart-drawer-footer">{bag.length > 0 && <><div className="total"><span>Subtotal</span><span>{money(subtotal)}</span></div><p>Delivery fees calculated during checkout.</p><Link className="primary bag-page-link" href="/cart" onClick={close}>Review bag & checkout →</Link></>}<button type="button" className="continue-shopping" onClick={close}>← Continue shopping</button><small>Prices and availability are checked again at checkout.</small></div>
  </dialog>;
}
