import { fetchJson } from './http.mjs';

export function startPromotionPolling(onUpdate, { request = fetchJson, intervalMs = 60000 } = {}) {
  let active = true;
  let loading = false;
  const load = async () => {
    if (!active || loading) return;
    loading = true;
    try {
      const body = await request('/api/promotions');
      if (active) onUpdate(Array.isArray(body.data) ? body.data : []);
    } catch {
      // Offers are optional; retain the last successful response until the next refresh.
    } finally {
      loading = false;
    }
  };
  load();
  const refresh = setInterval(load, intervalMs);
  return () => {
    active = false;
    clearInterval(refresh);
  };
}
