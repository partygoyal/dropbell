export function offerDate(value) {
  if (!value) return null;
  return new Date(/[zZ]|[+-]\d\d:\d\d$/.test(value) ? value : `${value.replace(' ', 'T')}Z`);
}

export function filterOffers(offers, { query = '', category = '', item = '', from = '', to = '' } = {}) {
  const start = from ? new Date(`${from}T00:00:00+05:30`) : null;
  const end = to ? new Date(`${to}T23:59:59.999+05:30`) : null;
  return offers.filter(offer => {
    if (query && !`${offer.title} ${offer.description || ''} ${offer.code || ''}`.toLowerCase().includes(query.toLowerCase().trim())) return false;
    if (start && offer.ends_at && offerDate(offer.ends_at) <= start) return false;
    if (end && offer.starts_at && offerDate(offer.starts_at) > end) return false;
    if (offer.scope !== 'all_items') {
      if (category && !(offer.category_ids || []).map(String).includes(String(category))) return false;
      if (item && !(offer.product_ids || []).map(String).includes(String(item))) return false;
    }
    return true;
  });
}
