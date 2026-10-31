'use client';

export default function GlobalError({ error, retry, reset }) {
  return <html lang="en"><body style={{ fontFamily: 'system-ui, sans-serif', color: '#091b51', background: '#f7fafc', padding: '48px 24px' }}><main style={{ maxWidth: 560, margin: '48px auto' }} role="alert"><h1>Let’s get Dropbell running again.</h1><p>We couldn’t load this page. Try again, or return home. If you were placing an order, check your orders before placing another.</p>{error.digest && <p>Support reference: {error.digest}</p>}<button onClick={() => (retry || reset)()} style={{ padding: '12px 24px', cursor: 'pointer' }}>Try again</button><p><a href="/">Return home</a> · <a href="/account/orders">Check my orders</a> · <a href="mailto:settings@dropbell.in">Contact support</a></p></main></body></html>;
}
