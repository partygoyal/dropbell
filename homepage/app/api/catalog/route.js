export async function GET(request) {
  try {
    const origin = process.env.LARAVEL_API_URL || 'http://127.0.0.1:8000';
    const incoming = new URL(request.url).searchParams;
    const params = new URLSearchParams();
    for (const key of ['locality_id', 'vendor_id']) {
      const value = incoming.get(key) || '';
      if (/^[1-9]\d*$/.test(value)) params.set(key, value);
    }
    const query = params.size ? `?${params}` : '';
    const response = await fetch(`${origin}/api/products${query}`, { cache: 'no-store', headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(8000) });
    if (!response.ok) throw new Error('Catalogue unavailable');
    const body = await response.json();
    if (!Array.isArray(body.data)) throw new Error('Invalid catalogue');
    return Response.json(body, { headers: { 'Cache-Control': 'no-store' } });
  } catch {
    return Response.json({ message: 'The catalogue is temporarily unavailable. Please try again shortly.' }, { status: 503, headers: { 'Cache-Control': 'no-store', 'Retry-After': '10' } });
  }
}
