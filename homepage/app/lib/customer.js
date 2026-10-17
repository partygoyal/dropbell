export async function customerApi(path, options = {}) {
  const response = await fetch(`/api/customer/${path}`, {
    ...options, headers: { 'Content-Type': 'application/json', ...options.headers }, cache: 'no-store',
    ...(options.body ? { body: JSON.stringify(options.body) } : {}),
  });
  const body = await response.json();
  if (!response.ok) {
    const error = new Error(Object.values(body.errors || {}).flat()[0] || body.message || 'Something went wrong. Please try again.');
    error.status = response.status; throw error;
  }
  return body;
}
