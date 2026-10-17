import { Suspense } from 'react';
import CategoryStorefront from '../components/category-storefront';

export default function CategoryLayout({ children }) {
  return <><Suspense fallback={<main><p>Getting your essentials ready…</p></main>}><CategoryStorefront /></Suspense>{children}</>;
}
