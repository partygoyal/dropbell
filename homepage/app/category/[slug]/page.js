import { notFound } from 'next/navigation';
import { categoryFromSlug } from '../../lib/category-routing';

export async function generateMetadata({ params }) {
  const { slug } = await params;
  return { title: `${categoryFromSlug(slug) || 'Category'} essentials | Dropbell` };
}

export default async function CategoryPage({ params }) {
  const { slug } = await params;
  const category = categoryFromSlug(slug);
  if (!category) notFound();
  return null;
}
