import Link from 'next/link';

export default function ShoppingTabs({ section }) {
  return <nav className="shopping-section-tabs" aria-label="Choose what to order"><div className="shopping-section-switch">
    <Link href="/food" className={`shopping-section-tab food-tab${section === 'food' ? ' selected' : ''}`} aria-current={section === 'food' ? 'page' : undefined}><span className="shopping-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M5 3v6a3 3 0 0 0 6 0V3M8 3v19M18 3v19M18 3c-5 2-5 9 0 9" /></svg></span><span><strong>Food</strong><small>Restaurants & freshly prepared meals</small></span>{section === 'food' && <span className="shopping-tab-check" aria-hidden="true">✓</span>}</Link>
    <Link href="/" className={`shopping-section-tab grocery-tab${section === 'groceries' ? ' selected' : ''}`} aria-current={section === 'groceries' ? 'page' : undefined}><span className="shopping-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="m3 9 3 12h12l3-12H3Zm4 0 5-7 5 7M9 13v4m6-4v4" /></svg></span><span><strong>Groceries</strong><small>Local shops & everyday essentials</small></span>{section === 'groceries' && <span className="shopping-tab-check" aria-hidden="true">✓</span>}</Link>
  </div></nav>;
}
