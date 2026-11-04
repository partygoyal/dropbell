export function homeProductGroups(products, { category = 'All', search = '', sort = 'featured', availability = 'all' } = {}) {
  const term = search.trim().toLocaleLowerCase();
  const matching = products.filter(product => (category === 'All' || product.cat === category)
    && (!term || `${product.name} ${product.cat} ${product.qty}`.toLocaleLowerCase().includes(term))
    && (availability !== 'orderable' || (!product.sample && (product.inventory == null || product.inventory > 0))));
  const groups = new Map();
  for (const product of matching) {
    if (!groups.has(product.cat)) groups.set(product.cat, []);
    groups.get(product.cat).push(product);
  }
  return [...groups].map(([name, items]) => {
    const ordered = [...items];
    if (sort === 'price-low') ordered.sort((a, b) => a.price - b.price || a.name.localeCompare(b.name));
    if (sort === 'price-high') ordered.sort((a, b) => b.price - a.price || a.name.localeCompare(b.name));
    if (sort === 'name') ordered.sort((a, b) => a.name.localeCompare(b.name));
    return { name, products: ordered };
  });
}
