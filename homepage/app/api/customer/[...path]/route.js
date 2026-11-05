import { cookies } from 'next/headers';
import { NextResponse } from 'next/server';
const allowed = /^(auth\/(forgot-password|reset-password|login|register|request|verify|demo\/(?:request|verify))|support\/tickets(?:\/\d+(?:\/(?:messages|read))?)?|favourites|me|logout|quote|sample-quote|sample-orders|addresses(?:\/\d+)?|requests|transactions|orders(?:\/[a-zA-Z0-9-]+(?:\/(?:requests|invoice|reorder|reviews|tracking))?)?)$/;
async function proxy(request, { params }) {
  const { path } = await params;
  const endpoint = path.join('/');
  if (!allowed.test(endpoint)) return NextResponse.json({ message: 'Not found.' }, { status: 404 });
  if (request.method !== 'GET') {
    const origin = request.headers.get('origin');
    if (origin) {
      try {
        const source = new URL(origin);
        if (source.host !== request.headers.get('host') || !['http:', 'https:'].includes(source.protocol)) throw new Error('Invalid origin');
      } catch { return NextResponse.json({ message: 'Invalid request origin.' }, { status: 403 }); }
    }
    if (!request.headers.get('content-type')?.includes('application/json')) return NextResponse.json({ message: 'JSON required.' }, { status: 415 });
  }
  const jar = await cookies();
  const token = jar.get('dropbell_customer')?.value;
  try {
    const upstream = await fetch(`${process.env.LARAVEL_API_URL || 'http://127.0.0.1:8000'}/api/customer/${endpoint}${new URL(request.url).search}`, {
      method: request.method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
      ...(request.method !== 'GET' ? { body: await request.text() } : {}), cache: 'no-store', signal: AbortSignal.timeout(15000),
    });
    if (endpoint.endsWith('/invoice') && upstream.ok) {
      return new NextResponse(await upstream.text(), { status: 200, headers: { 'Content-Type': 'application/octet-stream', 'Content-Disposition': upstream.headers.get('content-disposition') || 'attachment; filename="invoice.html"', 'Cache-Control': 'private, no-store', 'X-Content-Type-Options': 'nosniff' } });
    }
    if (upstream.status >= 500) return NextResponse.json({ message: 'Dropbell is temporarily unavailable. Please try again shortly.' }, { status: 503, headers: { 'Cache-Control': 'no-store', 'Retry-After': '10' } });
    if (upstream.status === 401) {
      const response = NextResponse.json({ message: 'Your session expired. Please sign in again.' }, { status: 401, headers: { 'Cache-Control': 'no-store' } });
      response.cookies.set('dropbell_customer', '', { httpOnly: true, secure: process.env.NODE_ENV === 'production', sameSite: 'lax', path: '/', maxAge: 0 });
      return response;
    }
    const body = await upstream.json();
    if (!body || typeof body !== 'object' || Array.isArray(body)) throw new Error('Invalid response');
    const issuedToken = ['auth/login','auth/register','auth/verify', 'auth/demo/verify', 'sample-orders'].includes(endpoint) && upstream.ok ? body.token : null;
    delete body.token;
    const response = NextResponse.json(body, { status: upstream.status, headers: { 'Cache-Control': 'no-store' } });
    if (issuedToken) response.cookies.set('dropbell_customer', issuedToken, { httpOnly: true, secure: process.env.NODE_ENV === 'production', sameSite: 'lax', path: '/', maxAge: 604800 });
    if ((endpoint === 'logout' && upstream.ok) || upstream.status === 401) response.cookies.set('dropbell_customer', '', { httpOnly: true, sameSite: 'lax', path: '/', maxAge: 0 });
    return response;
  } catch { return NextResponse.json({ message: 'Could not reach Dropbell. Please try again.' }, { status: 503, headers: { 'Cache-Control': 'no-store', 'Retry-After': '10' } }); }
}
export const GET = proxy;
export const POST = proxy;
export const PUT = proxy;
export const PATCH = proxy;
export const DELETE = proxy;
