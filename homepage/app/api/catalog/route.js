export async function GET(request) {
  try {
    const origin = process.env.LARAVEL_API_URL || 'http://127.0.0.1:8000';
    const pin = new URL(request.url).searchParams.get('postal_code') || '';
    const query = /^[1-9]\d{5}$/.test(pin) ? `?postal_code=${pin}` : '';
    const response = await fetch(`${origin}/api/products${query}`, { cache: 'no-store', headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(8000) });
    if (!response.ok) throw new Error('Catalogue unavailable');
    const body = await response.json();
    if (!Array.isArray(body.data)) throw new Error('Invalid catalogue');
    return Response.json(body, { headers: { 'Cache-Control': 'no-store' } });
  } catch {
    return Response.json({ message: 'The catalogue is temporarily unavailable. Please check that Laravel is running.' }, { status: 503 });
  }
}
