'use client';

import { useEffect, useRef } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

export default function DeliveryMap({ value, onChange, disabled }) {
  const container = useRef(null);
  const map = useRef(null);
  const marker = useRef(null);
  const callback = useRef(onChange);
  const locked = useRef(disabled);
  callback.current = onChange; locked.current = disabled;
  useEffect(() => {
    const hasPin = Number.isFinite(value.latitude) && Number.isFinite(value.longitude);
    const current = L.map(container.current).setView(hasPin ? [value.latitude, value.longitude] : [22.5, 79], hasPin ? 16 : 4);
    map.current = current;
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>' }).addTo(current);
    current.on('click', event => { if (!locked.current) callback.current({ latitude: event.latlng.lat, longitude: event.latlng.lng }); });
    return () => { current.remove(); map.current = null; marker.current = null; };
  }, []);
  useEffect(() => {
    if (!map.current) return;
    if (!Number.isFinite(value.latitude) || !Number.isFinite(value.longitude)) {
      if (marker.current) marker.current.remove();
      marker.current = null;
      return;
    }
    const point = [value.latitude, value.longitude];
    if (marker.current) marker.current.setLatLng(point);
    else marker.current = L.marker(point, { icon: L.divIcon({ html: '<span aria-hidden="true" style="font-size:36px;line-height:40px">📍</span>', className: 'delivery-map-pin', iconSize: [36, 40], iconAnchor: [18, 38] }) }).addTo(map.current);
    map.current.setView(point, Math.max(15, map.current.getZoom()));
  }, [value.latitude, value.longitude]);
  return <div ref={container} role="region" aria-label="Tap the map to choose your delivery doorstep" style={{ height: 340, marginTop: 16, borderRadius: 16, zIndex: 0 }} />;
}
