export function sectionCatalogue({ products, categories, subCategories, vendors }, section) {
  const selectedCategories = categories.filter(item => (item.shopping_section || 'groceries') === section);
  const categoryIds = new Set(selectedCategories.map(item => Number(item.id)));
  return {
    products: products.filter(item => (item.section || 'groceries') === section),
    categories: selectedCategories,
    subCategories: subCategories.filter(item => categoryIds.has(Number(item.category_id))),
    vendors: vendors.filter(item => (item.sections || ['groceries']).includes(section)).map(item => ({ ...item, product_count: item.section_counts?.[section] ?? item.product_count })),
  };
}

export function sectionPromotions(promotions, section) {
  return promotions.filter(item => item.banner_section === 'all' || (item.banner_section || 'groceries') === section);
}
