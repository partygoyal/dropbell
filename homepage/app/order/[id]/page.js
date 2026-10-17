import Storefront from '../../components/storefront';
export const metadata = { title: 'Your demo order | Dropbell' };
export default async function OrderPage({ params }) {
  const { id } = await params;
  return <Storefront key={id} view="order" orderId={id} />;
}
