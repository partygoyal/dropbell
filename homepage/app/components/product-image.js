'use client';

import { useState } from 'react';

export default function ProductImage({ product, large = false }) {
  const [failedSource, setFailedSource] = useState(null);
  const photo = product.photo;
  const source = photo && (/^(https?:\/\/|\/)/.test(photo) ? photo : `https://images.unsplash.com/${photo}?auto=format&fit=crop&w=${large ? 1000 : 500}&q=85`);

  return source && failedSource !== source
    ? <img src={source} alt={product.name} loading={large ? 'eager' : 'lazy'} decoding="async" onError={() => setFailedSource(source)} />
    : <span className="product-image-pending"><span aria-hidden="true">▧</span><small>Photo coming soon</small></span>;
}
