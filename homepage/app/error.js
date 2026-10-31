'use client';

export default function ErrorPage({ error, retry, reset }) {
  return <main className="missing-page" role="alert"><p className="eyebrow">LET’S GET YOU BACK</p><h1>Something interrupted your visit.</h1><p>Try loading this page again. If you were placing an order, check your order history before starting another checkout.</p>{error.digest && <p>Support reference: {error.digest}</p>}<button className="primary" onClick={() => (retry || reset)()}>Try again</button><p><a href="/account/orders">Check my orders</a> · <a href="/">Return home</a> · <a href="mailto:settings@dropbell.in">Contact support</a></p></main>;
}
