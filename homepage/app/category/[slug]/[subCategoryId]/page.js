import { notFound } from 'next/navigation';
import { categoryFromSlug } from '../../../lib/category-routing';

export async function generateMetadata({ params }) {
  const { slug } = await params;
  return { title: `${categoryFromSlug(slug) || 'Category'} products | Dropbell` };
}

export default async function SubCategoryProductsPage({ params }) {
  const { slug, subCategoryId } = await params;
  if (!categoryFromSlug(slug) || !/^[1-9]\d*$/.test(subCategoryId)) notFound();
  return null;
}
