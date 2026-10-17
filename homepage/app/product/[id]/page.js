import { notFound } from 'next/navigation';
import Storefront from '../../components/storefront';

export default async function ProductPage({ params }) {
  const { id } = await params;
  if (!/^[1-9]\d*$/.test(id)) notFound();
  return <Storefront key={id} view="product" productId={Number(id)} />;
}
