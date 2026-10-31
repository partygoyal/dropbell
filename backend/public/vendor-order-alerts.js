(() => {
  const root = document.querySelector('[data-vendor-alerts]');
  if (!root) return;
  const button = root.querySelector('[data-alert-enable]');
  const stop = root.querySelector('[data-alert-stop]');
  const status = root.querySelector('[data-alert-status]');
  const audio = new Audio(root.dataset.sound);
  audio.preload = 'none';
  const key = `dropbell:vendor:${root.dataset.vendorId}:order-cursor`;
  let cursor = null, enabled = false, busy = false, stopped = false;
  try { const saved = sessionStorage.getItem(key); if (saved !== null && /^\d+$/.test(saved)) cursor = Number(saved); } catch {}
  function stopSound() { audio.pause(); audio.currentTime = 0; stop.hidden = true; }
  async function play() {
    try { audio.currentTime = 0; await audio.play(); stop.hidden = false; }
    catch { enabled = false; button.textContent = 'Enable & test order sounds'; status.textContent = 'Sound was blocked. Click Enable to allow new order sounds.'; }
  }
  audio.addEventListener('ended', () => { stop.hidden = true; });
  stop.addEventListener('click', stopSound);
  button.addEventListener('click', async () => {
    enabled = !enabled;
    button.textContent = enabled ? 'Mute order sounds' : 'Enable & test order sounds';
    status.textContent = enabled ? 'Order sound alerts enabled for this tab.' : 'Order sounds muted.';
    if (enabled) await play(); else stopSound();
  });
  async function poll() {
    if (busy || stopped) return;
    busy = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const url = new URL(root.dataset.url);
      if (cursor !== null) url.searchParams.set('after', String(cursor));
      const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal, cache: 'no-store' });
      if ([401, 403].includes(response.status) || (response.redirected && response.url.includes('/vendor/login'))) { stopped = true; stopSound(); status.textContent = 'Sign in again to receive order alerts.'; return; }
      if (!response.ok) throw Error('Alert refresh failed');
      const data = await response.json();
      if (!Number.isSafeInteger(data.cursor) || !Array.isArray(data.orders)) throw Error('Invalid response');
      if (cursor !== null && data.orders.length) {
        status.textContent = `${data.orders.length} new order${data.orders.length === 1 ? '' : 's'} received. Open Overview to see the latest items.`;
        if (enabled) await play();
      }
      cursor = data.cursor;
      try { sessionStorage.setItem(key, String(cursor)); } catch {}
    } catch { if (!stopped) status.textContent = 'Checking for orders paused. Retrying automatically…'; }
    finally { clearTimeout(timeout); busy = false; }
  }
  poll();
  let timer = setInterval(poll, 15000);
  window.addEventListener('pageshow', event => { if (event.persisted) { clearInterval(timer); timer = setInterval(poll, 15000); poll(); } });
  window.addEventListener('focus', poll);
  window.addEventListener('pagehide', () => { clearInterval(timer); stopSound(); });
})();
