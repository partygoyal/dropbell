const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function readCheckoutAttempt(storage, key) {
  const raw = storage.getItem(key);
  if (raw === null) return null;
  const saved = JSON.parse(raw);
  if (!saved || !['orders', 'sample-orders'].includes(saved.endpoint)
    || !uuid.test(saved.payload?.request_key || '')
    || !Array.isArray(saved.payload?.items) || !saved.payload.items.length
    || !saved.payload.address || !Number.isSafeInteger(saved.payload.expected_total)) {
    throw new Error('Saved checkout could not be read. Check your orders before starting another checkout.');
  }
  return saved;
}

export function saveCheckoutAttempt(storage, key, attempt) {
  // Preserve an unresolved attempt even when the bag or address has changed.
  const pending = readCheckoutAttempt(storage, key);
  if (pending) return pending;
  storage.setItem(key, JSON.stringify(attempt));
  return attempt;
}
