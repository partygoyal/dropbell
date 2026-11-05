import {
  Menu, ArrowRight, ArrowUpRight, ArrowUp, ArrowLeft, UserRound, ShoppingCart,
  Store, Leaf, ShieldCheck, Truck, IndianRupee, Headset, Milk, Croissant,
  Gift, MapPin, ChevronDown, Search, LayoutGrid, Copy, Heart, House,
  Check, X, Plus, Minus, Package, Star, UtensilsCrossed, ShoppingBasket,
} from 'lucide-react';

const icons = {
  menu: Menu, arrow: ArrowRight, 'arrow-up-right': ArrowUpRight, 'arrow-up': ArrowUp,
  'arrow-left': ArrowLeft, user: UserRound, cart: ShoppingCart, store: Store,
  leaf: Leaf, shield: ShieldCheck, truck: Truck, rupee: IndianRupee,
  support: Headset, milk: Milk, bread: Croissant, sweet: Gift, pin: MapPin,
  chevron: ChevronDown, search: Search, grid: LayoutGrid, copy: Copy,
  heart: Heart, home: House, check: Check, close: X, plus: Plus, minus: Minus,
  package: Package, star: Star, food: UtensilsCrossed, groceries: ShoppingBasket,
};

export default function SiteIcon({ name, className = '', size = 20, filled = false }) {
  const Icon = icons[name] || Package;
  return <Icon className={`site-icon ${className}`} size={size} strokeWidth={1.8} fill={filled ? 'currentColor' : 'none'} aria-hidden="true" focusable="false" />;
}
