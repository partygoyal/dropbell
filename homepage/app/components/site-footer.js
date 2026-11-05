'use client';

import SiteIcon from './site-icon';
import Link from 'next/link';
import { MarketBrand, MarketIcon } from './marketplace-home';

export default function SiteFooter({ section = 'food', categories = [], onOpenModal }) {
  const food = section === 'food';
  return <footer className="market-footer jumbo-footer refreshed-footer">
    <div className="footer-inner">
      <section className="footer-invitation" aria-labelledby="footer-invitation-title">
        <div><p className="footer-kicker">GOOD FOOD. GOOD NEIGHBOURS. ONE DROPBELL.</p><h2 id="footer-invitation-title">A little closer.<br/><span>A whole lot easier.</span></h2><p>Your next favourite meal. Your everyday essentials.<br/>Find them in your neighbourhood.</p></div>
        <div className="footer-invitation-actions"><Link className="footer-food-button" href="/food"><MarketIcon name="store"/>Explore food <span aria-hidden="true"><SiteIcon name="arrow-up-right" size={16} /></span></Link><Link className="footer-grocery-button" href="/groceries"><MarketIcon name="leaf"/>Shop groceries <span aria-hidden="true"><SiteIcon name="arrow-up-right" size={16} /></span></Link><span>Local discoveries, delivered to your doorstep.</span></div>
        <span className="footer-invitation-spark" aria-hidden="true">✦</span>
      </section>
      <div className="footer-reassurance" aria-label="Shopping with Dropbell">
        <div><span className="footer-proof-icon"><MarketIcon name="pin"/></span><span><b>From your neighbourhood</b><small>Discover shops & kitchens close to home</small></span></div>
        <div><span className="footer-proof-icon"><MarketIcon name="rupee"/></span><span><b>Pay on delivery</b><small>Review your total before ordering</small></span></div>
        <Link href="/account/support"><span className="footer-proof-icon"><MarketIcon name="support"/></span><span><b>A little help, whenever you need it</b><small>Order questions? We’re here to help.</small></span></Link>
      </div>
      <div className="jumbo-footer-main">
        <div className="jumbo-footer-brand"><Link href="/" aria-label="Dropbell home"><MarketBrand caption={false}/></Link><p>Fresh meals, everyday essentials and the local businesses that make your neighbourhood feel like home.</p><span className="footer-city-badge"><span aria-hidden="true"><SiteIcon name="pin" size={16} /></span>Dehradun, Uttarakhand</span><a className="footer-contact" href="mailto:settings@dropbell.in">settings@dropbell.in <span aria-hidden="true"><SiteIcon name="arrow-up-right" size={16} /></span></a></div>
        <nav aria-label="Shopping links"><h3>Find your favourites</h3><Link href="/food">Food & restaurants</Link><Link href="/groceries">Groceries & essentials</Link><Link href="/food#restaurants-title">Restaurants near you</Link><Link href="/groceries#stores">Local shops</Link><Link href="/offers">Offers & coupons</Link><Link href="/account/favourites">Saved favourites</Link></nav>
        <nav aria-label="Account and support"><h3>Your Dropbell</h3><Link href="/account">My account</Link><Link href="/account/orders">Orders & tracking</Link><Link href="/account/addresses">Saved addresses</Link><Link href="/cart">Shopping bag</Link><Link href="/account/support">Customer support</Link><Link href="/account/requests">Returns & refunds</Link><Link href="/help">Help & FAQs</Link></nav>
        <nav aria-label="Company and partners"><h3>Good things grow locally</h3><a href="/vendor/register">List your restaurant <span aria-hidden="true"><SiteIcon name="arrow-up-right" size={16} /></span></a><a href="/vendor/register">Become a shop partner <span aria-hidden="true"><SiteIcon name="arrow-up-right" size={16} /></span></a><a href="/vendor/login">Vendor login</a><button type="button" onClick={() => onOpenModal('partner')}>Deliver with Dropbell</button><button type="button" onClick={() => onOpenModal('about')}>Our story</button><a href="/food-photo-credits.html">Food photo credits</a><span className="footer-partner-note"><span aria-hidden="true"><SiteIcon name="heart" size={16} /></span>Built around local businesses.</span></nav>
      </div>
      {categories.length > 0 && <section className="jumbo-footer-categories"><div className="footer-category-heading"><h3>{food ? 'What are you craving?' : 'A little of everything you need'}</h3><Link href={food ? '/food' : '/groceries'}>Explore {food ? 'food' : 'groceries'} <span aria-hidden="true"><SiteIcon name="arrow" size={16} /></span></Link></div><div>{categories.filter(item => item.name !== 'All').map(item => <Link key={item.id || item.name} href={`/category/${encodeURIComponent(item.name.toLowerCase())}`}>{item.name}</Link>)}</div></section>}
      <div className="footer-signoff"><span>A little drop.<br/><b>A better day.</b></span><button type="button" className="footer-back-top" onClick={() => window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })}>Back to top <span aria-hidden="true"><SiteIcon name="arrow-up" size={16} /></span></button></div>
      <div className="jumbo-footer-bottom"><small>© {new Date().getFullYear()} Dropbell Delivery Company Private Limited. All rights reserved.</small><p className="footer-developer-credit">Designed and developed by <a href="https://www.espertotech.in/" target="_blank" rel="noopener noreferrer">Esperto Technologies <span aria-hidden="true"><SiteIcon name="arrow-up-right" size={16} /></span></a></p><div className="footer-legal"><button type="button" onClick={() => onOpenModal('terms')}>Terms & conditions</button><span aria-hidden="true">·</span><button type="button" onClick={() => onOpenModal('privacy')}>Privacy policy</button></div></div>
    </div>
  </footer>;
}
