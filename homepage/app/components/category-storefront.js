'use client';

import { useParams } from 'next/navigation';
import Storefront from './storefront';
import { categoryFromSlug } from '../lib/category-routing';

export default function CategoryStorefront() {
  const { slug } = useParams();
  return <Storefront view="catalog" category={categoryFromSlug(slug) || 'All'} />;
}
