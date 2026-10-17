import Link from 'next/link';
export default function NotFound() {
  return <main className="missing-page"><p className="eyebrow">A LITTLE DETOUR</p><h1>We couldn’t find that shelf.</h1><p>Let’s get you back to your everyday favourites.</p><Link className="primary" href="/category/all">Browse essentials →</Link></main>;
}
