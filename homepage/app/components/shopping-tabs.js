import SiteIcon from './site-icon';
import Link from 'next/link';

export default function ShoppingTabs({ section }) {
  return <nav className="shopping-section-tabs" aria-label="Choose what to order"><div className="shopping-section-switch">
    <Link href="/food" className={`shopping-section-tab food-tab${section === 'food' ? ' selected' : ''}`} aria-current={section === 'food' ? 'page' : undefined}><span className="shopping-tab-icon" aria-hidden="true"><SiteIcon name="food" /></span><span><strong>Food</strong><small>Restaurants & freshly prepared meals</small></span>{section === 'food' && <span className="shopping-tab-check" aria-hidden="true"><SiteIcon name="check" size={16} /></span>}</Link>
    <Link href="/groceries" className={`shopping-section-tab grocery-tab${section === 'groceries' ? ' selected' : ''}`} aria-current={section === 'groceries' ? 'page' : undefined}><span className="shopping-tab-icon" aria-hidden="true"><SiteIcon name="groceries" /></span><span><strong>Groceries</strong><small>Local shops & everyday essentials</small></span>{section === 'groceries' && <span className="shopping-tab-check" aria-hidden="true"><SiteIcon name="check" size={16} /></span>}</Link>
  </div></nav>;
}
