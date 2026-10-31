import { notFound } from 'next/navigation';
import Storefront from '../../components/storefront';
export const metadata = { title: 'Shop local | Dropbell' };
export default async function ShopPage({ params }) {
  const { id } = await params;
  if (!/^[1-9]\d*$/.test(id) || !Number.isSafeInteger(Number(id))) notFound();
  return <Storefront key={id} view="shop" vendorId={Number(id)} />;
}
