import http from 'node:http';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import assert from 'node:assert/strict';
import { once } from 'node:events';

let available = true;
const upstream = http.createServer((req, res) => {
  res.setHeader('Content-Type', 'application/json');
  if (req.url === '/api/locations') {
    res.statusCode = available ? 200 : 503;
    res.end(JSON.stringify(available ? { data: [] } : { message: 'database password secret' }));
  } else if (req.url.startsWith('/api/products')) {
    const query = new URL(req.url, 'http://localhost').searchParams;
    res.end(JSON.stringify({ data: query.get('vendor_id') === '11' && query.get('locality_id') === '7' ? [{ id: 91, name: 'Chosen shop product', price_paise: 6500 }] : [], vendors: [{ id: 11, name: 'Corner Shop', city: 'Dehradun', product_count: 1 }], categories: [], sub_categories: [] }));
  }
  else if (req.url === '/api/customer/auth/login') res.end('{"token":"private-upstream-token","data":{"name":"Smoke"}}');
  else if (req.url === '/api/customer/me') { res.statusCode = 401; res.end('<html>expired</html>'); }
  else if (req.url === '/api/customer/quote') { res.statusCode = 500; res.end('{"message":"database password secret"}'); }
  else res.end('[]');
});
await new Promise(resolve => upstream.listen(18001, '127.0.0.1', resolve));
const server = spawn(process.execPath, ['node_modules/next/dist/bin/next', 'start', '--hostname', '127.0.0.1', '--port', '13001'], {
  cwd: fileURLToPath(new URL('..', import.meta.url)),
  env: { ...process.env, NODE_ENV: 'production', LARAVEL_API_URL: 'http://127.0.0.1:18001' }, stdio: ['ignore', 'pipe', 'pipe'],
});
let output = '';
server.stdout.on('data', chunk => { output += chunk; });
server.stderr.on('data', chunk => { output += chunk; });
const url = 'http://127.0.0.1:13001';
try {
  let ready = false;
  for (let attempt = 0; attempt < 50; attempt++) {
    try { if ((await fetch(url)).ok) { ready = true; break; } } catch {}
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  assert.ok(ready, output);
  for (const route of ['/', '/login', '/checkout', '/account/support', '/forgot-password', '/shops', '/shops/11']) {
    const response = await fetch(url + route);
    assert.equal(response.status, 200, route);
    assert.equal(response.headers.get('x-content-type-options'), 'nosniff');
    const html = await response.text();
    if (route !== '/forgot-password') assert.match(html, /class="vendor-login-button" href="https:\/\/dropbell\.in\/vendor\/login"/);
  }
  const shopCatalogue = await fetch(url + '/api/catalog?locality_id=7&vendor_id=11');
  assert.equal(shopCatalogue.status, 200);
  const shopBody = await shopCatalogue.json();
  assert.equal(shopBody.data[0].price_paise, 6500);
  assert.equal(shopBody.vendors[0].name, 'Corner Shop');
  const missingShop = await fetch(url + '/shops/invalid');
  const missingHtml = await missingShop.text();
  assert.match(missingHtml, /find that shelf/);
  assert.match(missingHtml, /name="robots" content="noindex"/);
  assert.equal((await fetch(url + '/api/health')).status, 200);
  available = false;
  const health = await fetch(url + '/api/health');
  assert.equal(health.status, 503);
  assert.equal(health.headers.get('cache-control'), 'no-store');
  available = true;
  const post = { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: url }, body: '{}' };
  const login = await fetch(url + '/api/customer/auth/login', post);
  assert.equal(login.status, 200);
  assert.match(login.headers.get('set-cookie'), /HttpOnly/i);
  assert.match(login.headers.get('set-cookie'), /Secure/i);
  assert.equal((await login.json()).token, undefined);
  const expired = await fetch(url + '/api/customer/me');
  assert.equal(expired.status, 401);
  assert.match(expired.headers.get('set-cookie'), /Max-Age=0/i);
  const failure = await fetch(url + '/api/customer/quote', post);
  assert.equal(failure.status, 503);
  assert.doesNotMatch(await failure.text(), /password|secret/);
  const invalid = await fetch(url + '/api/customer/support/tickets');
  assert.equal(invalid.status, 503);
  const origin = await fetch(url + '/api/customer/quote', { ...post, headers: { ...post.headers, Origin: 'not-a-url' } });
  assert.equal(origin.status, 403);
  const html = await fetch(url + '/api/customer/auth/login', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: '{}' });
  assert.equal(html.status, 415);
  assert.equal((await fetch(url + '/api/customer/not-allowed')).status, 404);
  console.log('Production HTTP smoke passed: 7 pages, shop/location forwarding, security headers, readiness/outage, token isolation, expired session, sanitized failures, invalid JSON shape, origin validation, content type and route allowlist.');
} finally {
  server.kill('SIGTERM');
  await once(server, 'exit');
  await new Promise(resolve => upstream.close(resolve));
}
