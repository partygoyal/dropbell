export const dynamic = 'force-dynamic';

export async function GET() {
  const headers = { 'Cache-Control': 'no-store' };
  try {
    const origin = process.env.LARAVEL_API_URL || 'http://127.0.0.1:8000';
    const response = await fetch(`${origin}/api/locations`, {
      cache: 'no-store', headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(5000),
    });
    if (!response.ok || !Array.isArray((await response.json()).data)) throw new Error('Unavailable');
    return Response.json({ status: 'ready' }, { headers });
  } catch {
    return Response.json({ status: 'unavailable' }, { status: 503, headers: { ...headers, 'Retry-After': '10' } });
  }
}
