'use client';
import {useEffect,useRef} from 'react';
import 'leaflet/dist/leaflet.css';
export default function TrackingMap({location,destination}){
 const container=useRef(null),map=useRef(null),marker=useRef(null);
 useEffect(()=>{let cancelled=false;import('leaflet').then(module=>{if(cancelled||!container.current)return;const L=module.default;map.current=L.map(container.current,{scrollWheelZoom:false}).setView([location.latitude,location.longitude],15);L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap contributors',maxZoom:19}).addTo(map.current);marker.current=L.circleMarker([location.latitude,location.longitude],{radius:9,color:'#fff',weight:3,fillColor:'#075cff',fillOpacity:1}).addTo(map.current).bindTooltip('Delivery partner');if(Number.isFinite(Number(destination?.latitude))&&destination?.latitude!=null&&destination?.longitude!=null){const target=[Number(destination.latitude),Number(destination.longitude)];L.circleMarker(target,{radius:8,color:'#d83937',fillOpacity:1}).addTo(map.current).bindTooltip('Delivery address');map.current.fitBounds([[location.latitude,location.longitude],target],{padding:[30,30],maxZoom:16});}}).catch(()=>{});return()=>{cancelled=true;map.current?.remove();map.current=null;marker.current=null;};},[]);
 useEffect(()=>{marker.current?.setLatLng([location.latitude,location.longitude]);},[location.latitude,location.longitude]);
 return <div ref={container} role="img" aria-label="Live delivery partner location; blue marker is the delivery partner and red marker is your address" style={{height:280,width:'100%',borderRadius:12,background:'#eaf1f6',margin:'16px 0'}}/>;
}
