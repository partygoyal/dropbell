async function proxy(request) {
  try {
    const origin = process.env.LARAVEL_API_URL || 'http://127.0.0.1:8000';
    const response = await fetch(`${origin}/api/promotions${request.method === 'POST' ? '/validate' : ''}`, {
      method: request.method, cache: 'no-store', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, signal: AbortSignal.timeout(8000),
      ...(request.method === 'POST' ? { body: await request.text() } : {}),
    });
    return Response.json(await response.json(), { status: response.status, headers: { 'Cache-Control': 'no-store' } });
  } catch { return Response.json({ message: 'Promotions are temporarily unavailable.' }, { status: 503 }); }
}
export const GET = proxy;
export const POST = proxy;
