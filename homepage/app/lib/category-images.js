const categoryImageNames = [
  'Fruits & Vegetables', 'Dairy, Bread & Eggs', 'Atta, Rice & Pulses', 'Oils, Spices & Cooking Essentials',
  'Snacks & Namkeen', 'Biscuits, Chocolates & Sweets', 'Breakfast & Spreads', 'Instant & Packaged Foods',
  'Sauces, Pickles & Condiments', 'Beverages', 'Frozen Foods & Ice Cream', 'Personal Care & Hygiene',
  'Beauty & Grooming', 'Cleaning & Household Essentials', 'Baby Care', 'Pet Care',
];
const categoryImages = { ...Object.fromEntries(categoryImageNames.map((name, index) => [name, index])), Fresh: 0, Breakfast: 1, Snacks: 4, Home: 13 };
export function categoryImageStyle(name) {
  const index = categoryImages[name];
  return index === undefined
    ? { backgroundSize: '100% 100%', backgroundPosition: 'center' }
    : { backgroundPosition: `${(index % 4) * 100 / 3}% ${Math.floor(index / 4) * 100 / 3}%` };
}

const subCategoryImages = {
  "Fruits & Vegetables::Fresh Fruits": [
    0,
    0,
    0
  ],
  "Fruits & Vegetables::Fresh Vegetables": [
    0,
    1,
    0
  ],
  "Fruits & Vegetables::Leafy Greens": [
    0,
    2,
    0
  ],
  "Fruits & Vegetables::Herbs & Seasonings": [
    0,
    3,
    0
  ],
  "Fruits & Vegetables::Exotic Fruits & Vegetables": [
    0,
    4,
    0
  ],
  "Fruits & Vegetables::Organic Produce": [
    0,
    5,
    0
  ],
  "Fruits & Vegetables::Cut & Packed Produce": [
    0,
    6,
    0
  ],
  "Dairy, Bread & Eggs::Milk": [
    0,
    0,
    1
  ],
  "Dairy, Bread & Eggs::Curd & Yogurt": [
    0,
    1,
    1
  ],
  "Dairy, Bread & Eggs::Paneer & Cheese": [
    0,
    2,
    1
  ],
  "Dairy, Bread & Eggs::Butter & Cream": [
    0,
    3,
    1
  ],
  "Dairy, Bread & Eggs::Bread & Buns": [
    0,
    4,
    1
  ],
  "Dairy, Bread & Eggs::Eggs": [
    0,
    5,
    1
  ],
  "Dairy, Bread & Eggs::Flavoured Milk & Dairy Drinks": [
    0,
    6,
    1
  ],
  "Atta, Rice & Pulses::Atta & Whole Wheat Flour": [
    0,
    0,
    2
  ],
  "Atta, Rice & Pulses::Rice & Rice Products": [
    0,
    1,
    2
  ],
  "Atta, Rice & Pulses::Dals & Pulses": [
    0,
    2,
    2
  ],
  "Atta, Rice & Pulses::Besan, Maida & Other Flours": [
    0,
    3,
    2
  ],
  "Atta, Rice & Pulses::Millets & Grains": [
    0,
    4,
    2
  ],
  "Atta, Rice & Pulses::Poha & Sabudana": [
    0,
    5,
    2
  ],
  "Atta, Rice & Pulses::Organic Staples": [
    0,
    6,
    2
  ],
  "Oils, Spices & Cooking Essentials::Cooking Oils": [
    0,
    0,
    3
  ],
  "Oils, Spices & Cooking Essentials::Ghee": [
    0,
    1,
    3
  ],
  "Oils, Spices & Cooking Essentials::Whole Spices": [
    0,
    2,
    3
  ],
  "Oils, Spices & Cooking Essentials::Ground & Blended Spices": [
    0,
    3,
    3
  ],
  "Oils, Spices & Cooking Essentials::Salt & Sugar": [
    0,
    4,
    3
  ],
  "Oils, Spices & Cooking Essentials::Jaggery & Sweeteners": [
    0,
    5,
    3
  ],
  "Oils, Spices & Cooking Essentials::Baking Ingredients": [
    0,
    6,
    3
  ],
  "Snacks & Namkeen::Potato Chips": [
    1,
    0,
    0
  ],
  "Snacks & Namkeen::Nachos & Dips": [
    1,
    1,
    0
  ],
  "Snacks & Namkeen::Namkeen & Mixtures": [
    1,
    2,
    0
  ],
  "Snacks & Namkeen::Popcorn": [
    1,
    3,
    0
  ],
  "Snacks & Namkeen::Nuts & Seeds": [
    1,
    4,
    0
  ],
  "Snacks & Namkeen::Roasted & Healthy Snacks": [
    1,
    5,
    0
  ],
  "Snacks & Namkeen::Papad & Fryums": [
    1,
    6,
    0
  ],
  "Biscuits, Chocolates & Sweets::Glucose & Marie Biscuits": [
    1,
    0,
    1
  ],
  "Biscuits, Chocolates & Sweets::Cream Biscuits": [
    1,
    1,
    1
  ],
  "Biscuits, Chocolates & Sweets::Cookies & Digestives": [
    1,
    2,
    1
  ],
  "Biscuits, Chocolates & Sweets::Chocolates": [
    1,
    3,
    1
  ],
  "Biscuits, Chocolates & Sweets::Candies & Chewing Gum": [
    1,
    4,
    1
  ],
  "Biscuits, Chocolates & Sweets::Indian Sweets": [
    1,
    5,
    1
  ],
  "Biscuits, Chocolates & Sweets::Cakes & Packaged Desserts": [
    1,
    6,
    1
  ],
  "Breakfast & Spreads::Oats": [
    1,
    0,
    2
  ],
  "Breakfast & Spreads::Corn Flakes & Cereals": [
    1,
    1,
    2
  ],
  "Breakfast & Spreads::Muesli & Granola": [
    1,
    2,
    2
  ],
  "Breakfast & Spreads::Peanut & Nut Butters": [
    1,
    3,
    2
  ],
  "Breakfast & Spreads::Jams & Fruit Spreads": [
    1,
    4,
    2
  ],
  "Breakfast & Spreads::Honey": [
    1,
    5,
    2
  ],
  "Breakfast & Spreads::Breakfast Mixes": [
    1,
    6,
    2
  ],
  "Instant & Packaged Foods::Instant Noodles": [
    1,
    0,
    3
  ],
  "Instant & Packaged Foods::Pasta & Vermicelli": [
    1,
    1,
    3
  ],
  "Instant & Packaged Foods::Ready-to-Eat Meals": [
    1,
    2,
    3
  ],
  "Instant & Packaged Foods::Ready-to-Cook Mixes": [
    1,
    3,
    3
  ],
  "Instant & Packaged Foods::Instant Soups": [
    1,
    4,
    3
  ],
  "Instant & Packaged Foods::Canned & Tinned Foods": [
    1,
    5,
    3
  ],
  "Instant & Packaged Foods::Packaged Batters & Dough": [
    1,
    6,
    3
  ],
  "Sauces, Pickles & Condiments::Tomato Ketchup": [
    2,
    0,
    0
  ],
  "Sauces, Pickles & Condiments::Cooking & Pasta Sauces": [
    2,
    1,
    0
  ],
  "Sauces, Pickles & Condiments::Mayonnaise & Dressings": [
    2,
    2,
    0
  ],
  "Sauces, Pickles & Condiments::Pickles": [
    2,
    3,
    0
  ],
  "Sauces, Pickles & Condiments::Chutneys": [
    2,
    4,
    0
  ],
  "Sauces, Pickles & Condiments::Vinegar & Soy Sauce": [
    2,
    5,
    0
  ],
  "Sauces, Pickles & Condiments::Cooking Pastes": [
    2,
    6,
    0
  ],
  "Beverages::Water & Soda": [
    2,
    0,
    1
  ],
  "Beverages::Soft Drinks": [
    2,
    1,
    1
  ],
  "Beverages::Juices & Fruit Drinks": [
    2,
    2,
    1
  ],
  "Beverages::Tea": [
    2,
    3,
    1
  ],
  "Beverages::Coffee": [
    2,
    4,
    1
  ],
  "Beverages::Energy & Sports Drinks": [
    2,
    5,
    1
  ],
  "Beverages::Malted & Nutrition Drinks": [
    2,
    6,
    1
  ],
  "Frozen Foods & Ice Cream::Ice Cream Tubs": [
    2,
    0,
    2
  ],
  "Frozen Foods & Ice Cream::Ice Cream Cones & Bars": [
    2,
    1,
    2
  ],
  "Frozen Foods & Ice Cream::Frozen Vegetables": [
    2,
    2,
    2
  ],
  "Frozen Foods & Ice Cream::Frozen Fries & Potato Snacks": [
    2,
    3,
    2
  ],
  "Frozen Foods & Ice Cream::Frozen Vegetarian Snacks": [
    2,
    4,
    2
  ],
  "Frozen Foods & Ice Cream::Frozen Non-Vegetarian Foods": [
    2,
    5,
    2
  ],
  "Frozen Foods & Ice Cream::Frozen Breads & Meals": [
    2,
    6,
    2
  ],
  "Personal Care & Hygiene::Soaps & Body Wash": [
    2,
    0,
    3
  ],
  "Personal Care & Hygiene::Shampoo & Conditioner": [
    2,
    1,
    3
  ],
  "Personal Care & Hygiene::Hair Oils & Treatments": [
    2,
    2,
    3
  ],
  "Personal Care & Hygiene::Toothpaste & Toothbrushes": [
    2,
    3,
    3
  ],
  "Personal Care & Hygiene::Hand Wash & Sanitisers": [
    2,
    4,
    3
  ],
  "Personal Care & Hygiene::Menstrual Hygiene": [
    2,
    5,
    3
  ],
  "Personal Care & Hygiene::Tissues, Wipes & Adult Care": [
    2,
    6,
    3
  ],
  "Beauty & Grooming::Face Wash & Cleansers": [
    3,
    0,
    0
  ],
  "Beauty & Grooming::Moisturisers & Skin Care": [
    3,
    1,
    0
  ],
  "Beauty & Grooming::Sunscreen": [
    3,
    2,
    0
  ],
  "Beauty & Grooming::Deodorants & Fragrances": [
    3,
    3,
    0
  ],
  "Beauty & Grooming::Shaving & Hair Removal": [
    3,
    4,
    0
  ],
  "Beauty & Grooming::Makeup & Nail Care": [
    3,
    5,
    0
  ],
  "Beauty & Grooming::Hair Styling & Colour": [
    3,
    6,
    0
  ],
  "Cleaning & Household Essentials::Laundry Detergents": [
    3,
    0,
    1
  ],
  "Cleaning & Household Essentials::Fabric Care": [
    3,
    1,
    1
  ],
  "Cleaning & Household Essentials::Dishwashing Products": [
    3,
    2,
    1
  ],
  "Cleaning & Household Essentials::Floor & Surface Cleaners": [
    3,
    3,
    1
  ],
  "Cleaning & Household Essentials::Toilet & Bathroom Cleaners": [
    3,
    4,
    1
  ],
  "Cleaning & Household Essentials::Garbage Bags, Foils & Wraps": [
    3,
    5,
    1
  ],
  "Cleaning & Household Essentials::Cleaning Tools & Pest Control": [
    3,
    6,
    1
  ],
  "Baby Care::Diapers & Nappies": [
    3,
    0,
    2
  ],
  "Baby Care::Baby Wipes": [
    3,
    1,
    2
  ],
  "Baby Care::Baby Bath & Skin Care": [
    3,
    2,
    2
  ],
  "Baby Care::Baby Oils & Powders": [
    3,
    3,
    2
  ],
  "Baby Care::Infant Formula": [
    3,
    4,
    2
  ],
  "Baby Care::Baby Cereals & Food": [
    3,
    5,
    2
  ],
  "Baby Care::Feeding & Hygiene Essentials": [
    3,
    6,
    2
  ],
  "Pet Care::Dog Food": [
    3,
    0,
    3
  ],
  "Pet Care::Cat Food": [
    3,
    1,
    3
  ],
  "Pet Care::Pet Treats": [
    3,
    2,
    3
  ],
  "Pet Care::Pet Litter & Waste Bags": [
    3,
    3,
    3
  ],
  "Pet Care::Pet Shampoo & Grooming": [
    3,
    4,
    3
  ],
  "Pet Care::Pet Hygiene": [
    3,
    5,
    3
  ],
  "Pet Care::Food for Birds & Small Pets": [
    3,
    6,
    3
  ]
};

export function subCategoryImageStyle(category, subCategory) {
  const position = subCategoryImages[`${category}::${subCategory}`];
  if (!position) return categoryImageStyle(category);
  const [sheet, column, row] = position;
  return {
    backgroundImage: `url('/assets/subcategories/sheet-${sheet + 1}.png')`,
    backgroundSize: '700% 400%',
    backgroundPosition: `${column * 100 / 6}% ${row * 100 / 3}%`,
  };
}
