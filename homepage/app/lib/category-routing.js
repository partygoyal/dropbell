const categories = { all: 'All', fresh: 'Fresh', breakfast: 'Breakfast', snacks: 'Snacks', home: 'Home' };

export function categoryFromSlug(slug) {
  if (typeof slug !== 'string') return null;
  try {
    const decoded = decodeURIComponent(slug);
    return decoded && decoded.length <= 80 ? (categories[decoded] || decoded) : null;
  } catch { return null; }
}

