import { cookies } from 'next/headers';
import { NextResponse } from 'next/server';
const allowed = /^(auth\/(request|verify)|me|logout|quote|sample-quote|sample-orders|addresses(?:\/\d+)?|requests|transactions|orders(?:\/[a-zA-Z0-9-]+(?:\/(?:requests|invoice))?)?)$/;
async function proxy(request, { params }) {
  const { path } = await params;
  const endpoint = path.join('/');
  if (!allowed.test(endpoint)) return NextResponse.json({ message: 'Not found.' }, { status: 404 });
  if (request.method !== 'GET') {
    const origin = request.headers.get('origin');
    if (origin && (new URL(origin).host !== request.headers.get('host') || !['http:', 'https:'].includes(new URL(origin).protocol))) return NextResponse.json({ message: 'Invalid request origin.' }, { status: 403 });
    if (!request.headers.get('content-type')?.includes('application/json')) return NextResponse.json({ message: 'JSON required.' }, { status: 415 });
  }
  const jar = await cookies();
  const token = jar.get('dropbell_customer')?.value;
  try {
    const upstream = await fetch(`${process.env.LARAVEL_API_URL || 'http://127.0.0.1:8000'}/api/customer/${endpoint}`, {
      method: request.method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
      ...(request.method !== 'GET' ? { body: await request.text() } : {}), cache: 'no-store', signal: AbortSignal.timeout(15000),
    });
    if (endpoint.endsWith('/invoice') && upstream.ok) {
      return new NextResponse(await upstream.text(), { status: 200, headers: { 'Content-Type': 'application/octet-stream', 'Content-Disposition': upstream.headers.get('content-disposition') || 'attachment; filename="invoice.html"', 'Cache-Control': 'private, no-store', 'X-Content-Type-Options': 'nosniff' } });
    }
    const body = await upstream.json();
    const issuedToken = ['auth/verify', 'sample-orders'].includes(endpoint) && upstream.ok ? body.token : null;
    delete body.token;
    const response = NextResponse.json(body, { status: upstream.status, headers: { 'Cache-Control': 'no-store' } });
    if (issuedToken) response.cookies.set('dropbell_customer', issuedToken, { httpOnly: true, secure: process.env.NODE_ENV === 'production', sameSite: 'lax', path: '/', maxAge: 604800 });
    if ((endpoint === 'logout' && upstream.ok) || upstream.status === 401) response.cookies.set('dropbell_customer', '', { httpOnly: true, sameSite: 'lax', path: '/', maxAge: 0 });
    return response;
  } catch { return NextResponse.json({ message: 'Could not reach Dropbell. Please try again.' }, { status: 503 }); }
}
export const GET = proxy;
export const POST = proxy;
export const PUT = proxy;
export const PATCH = proxy;
export const DELETE = proxy;
