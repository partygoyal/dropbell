import { fetchJson } from './http.mjs';

export function customerApi(path, options = {}) {
  return fetchJson(`/api/customer/${path}`, {
    ...options, headers: { 'Content-Type': 'application/json', ...options.headers }, cache: 'no-store',
    ...(options.body ? { body: JSON.stringify(options.body) } : {}),
  });
}
