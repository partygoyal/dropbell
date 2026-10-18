import './globals.css';
import './marketplace.css';

export const metadata = {
  title: 'Dropbell | Dehradun’s Online Marketplace',
  description: 'Sab Kuch, Ab Aapke Ghar Tak. Shop groceries, fresh produce, dairy and everyday essentials from local stores in Dehradun.',
};

export default function RootLayout({ children }) {
  return <html lang="en"><body>{children}</body></html>;
}
