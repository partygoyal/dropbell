import './globals.css';

export const metadata = {
  title: 'Dropbell | A little drop. A better day.',
  description: 'Shop groceries and everyday essentials with Dropbell.',
};

export default function RootLayout({ children }) {
  return <html lang="en"><body>{children}</body></html>;
}
