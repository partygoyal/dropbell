'use client';
import { useEffect, useState } from 'react';

export default function ConnectionStatus() {
  const [offline, setOffline] = useState(false);
  useEffect(() => {
    const update = () => setOffline(!navigator.onLine);
    update();
    window.addEventListener('online', update);
    window.addEventListener('offline', update);
    return () => { window.removeEventListener('online', update); window.removeEventListener('offline', update); };
  }, []);
  return offline ? <div role="status" style={{ position: 'sticky', top: 0, zIndex: 1000, padding: '12px 20px', background: '#fff2cd', color: '#513900', textAlign: 'center' }}>You’re offline. Reconnect to refresh products or place an order. Your saved bag is kept on this device.</div> : null;
}
