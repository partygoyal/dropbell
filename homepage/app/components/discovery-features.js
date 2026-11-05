'use client';
import SiteIcon from './site-icon';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import dynamic from 'next/dynamic';
const TrackingMap=dynamic(()=>import('./tracking-map'),{ssr:false});
import { customerApi } from '../lib/customer';
import { fetchJson } from '../lib/http.mjs';

export function FavouriteButton({ kind = 'product', id }) {
  const [saved, setSaved] = useState(false), [busy, setBusy] = useState(false), [message, setMessage] = useState('');
  useEffect(() => { let active = true; customerApi('favourites').then(body => { if(active) setSaved(body.data.some(row => row.kind === kind && row.target_id === id)); }).catch(()=>{}); return ()=>{active=false;}; },[kind,id]);
  return <div><button type="button" className="secondary" disabled={busy} aria-pressed={saved} onClick={async()=>{ setBusy(true);setMessage('');try { await customerApi('favourites',{method:'PUT',body:{kind,target_id:id,saved:!saved}});setSaved(!saved); }catch(e){setMessage(e.message);}finally{setBusy(false);} }}><SiteIcon name="heart" size={16} filled={saved} /> {saved ? 'Saved' : 'Save favourite'}</button>{message && <p role="status">{message} <Link href="/login">Sign in</Link></p>}</div>;
}

export function FavouritesPanel() {
 const [rows,setRows]=useState([]),[error,setError]=useState(''),[loading,setLoading]=useState(true);
 const load=async()=>{try{setRows((await customerApi('favourites')).data);setError('');}catch(e){setError(e.message);}finally{setLoading(false);}};
 useEffect(()=>{let active=true;customerApi('favourites').then(b=>{if(active)setRows(b.data);}).catch(e=>{if(active)setError(e.message);}).finally(()=>{if(active)setLoading(false);});return()=>{active=false;};},[]);
 return <section className="purchase-panel"><h2>Your favourites</h2>{loading&&<p role="status">Loading favourites…</p>}{error&&<p role="alert">{error}</p>}{!loading&&!rows.length&&<p>Save products and restaurants to find them here.</p>}{rows.map(row=><div className="review-item" key={`${row.kind}-${row.target_id}`}><Link href={row.kind==='vendor'?`/shops/${row.target_id}`:`/product/${row.target_id}`}>{row.name}{!row.available&&' · Currently unavailable'}</Link><button className="text-button" onClick={async()=>{try{await customerApi('favourites',{method:'PUT',body:{...row,saved:false}});await load();}catch(e){setError(e.message);}}}>Remove</button></div>)}</section>;
}

export function ProductReviews({ id }) {
 const [rows,setRows]=useState([]),[page,setPage]=useState(1),[pages,setPages]=useState(1),[error,setError]=useState('');
 useEffect(()=>{let active=true;fetchJson(`/api/reviews/${id}?page=${page}`).then(b=>{if(active){setRows(b.data);setPages(b.last_page);setError('');}}).catch(e=>{if(active)setError(e.message);});return()=>{active=false;};},[id,page]);
 return <section className="purchase-panel"><h2>Verified-purchase reviews</h2>{error&&<p role="status">Reviews are temporarily unavailable.</p>}{!error&&!rows.length&&<p>No published reviews yet.</p>}{rows.map(r=><article key={r.id}><b>{r.rating}/5 · {r.reviewer} · Verified purchase</b><p>{r.comment}</p></article>)}{pages>1&&<div><button disabled={page<=1} onClick={()=>setPage(page-1)}>Previous</button> Page {page} of {pages} <button disabled={page>=pages} onClick={()=>setPage(page+1)}>Next</button></div>}</section>;
}

export function OrderReviewForm({ order }) {
 const [product,setProduct]=useState(String(order.items[0]?.id||'')),[rating,setRating]=useState('5'),[comment,setComment]=useState(''),[message,setMessage]=useState(''),[busy,setBusy]=useState(false);
 if(order.status!=='delivered'||order.isSample)return null;
 return <form className="purchase-panel" onSubmit={async e=>{e.preventDefault();setBusy(true);try{const b=await customerApi(`orders/${order.id}/reviews`,{method:'POST',body:{product_id:Number(product),rating:Number(rating),comment}});setMessage(b.message);setComment('');}catch(err){setMessage(err.message);}finally{setBusy(false);}}}><h2>Review your purchase</h2><label>Product<select value={product} onChange={e=>setProduct(e.target.value)}>{order.items.filter(i=>i.id).map(i=><option key={i.id} value={i.id}>{i.name}</option>)}</select></label><label>Rating<select value={rating} onChange={e=>setRating(e.target.value)}>{[5,4,3,2,1].map(n=><option key={n} value={n}>{n} stars</option>)}</select></label><label>Your experience<textarea required minLength={5} maxLength={1000} value={comment} onChange={e=>setComment(e.target.value)}/></label><button className="primary" disabled={busy}>Submit for moderation</button>{message&&<p role="status">{message}</p>}</form>;
}

export function DeliveryTracking({ order }) {
 const [data,setData]=useState(null),[error,setError]=useState('');
 useEffect(()=>{if(!['received','picking','out_for_delivery'].includes(order.status)||order.isSample)return;let active=true,busy=false;
 const load=async()=>{if(!active||busy||document.hidden)return;busy=true;try{const b=await customerApi(`orders/${order.id}/tracking`);if(active){setData(b.data);setError('');}}catch(e){if(active){setData(null);setError(e.message);}}finally{busy=false;}};
 load();const timer=setInterval(load,15000);document.addEventListener('visibilitychange',load);return()=>{active=false;clearInterval(timer);document.removeEventListener('visibilitychange',load);};},[order.id,order.status,order.isSample]);
 if(!['received','picking','out_for_delivery'].includes(order.status)||order.isSample)return null;
 return <section className="purchase-panel"><h2>Delivery estimate & live location</h2>{error&&<p role="status">{error}</p>}{data?.estimate&&<><b>{data.estimate.minutes==null?'Estimate needs updating':`Approximately ${data.estimate.minutes} min remaining`}</b><p>{data.estimate.note}</p></>}<p>{data?.message||'Checking delivery updates…'}</p>{data?.sharing&&data.location&&<><TrackingMap location={data.location} destination={order.address}/><p>Last update: {new Date(data.last_updated_at).toLocaleTimeString()} · accuracy about {data.location.accuracy_meters} m</p><a className="secondary" target="_blank" rel="noopener noreferrer" href={`https://www.openstreetmap.org/?mlat=${data.location.latitude}&mlon=${data.location.longitude}#map=16/${data.location.latitude}/${data.location.longitude}`}>View delivery partner on map <SiteIcon name="arrow-up-right" size={16} /></a></>}</section>;
}

export function DishOptions({ product, initial, onSave }) {
 const [ids,setIds]=useState(initial?.ids||[]),[error,setError]=useState('');
 const choices=product.optionGroups.flatMap(g=>g.choices),extra=choices.filter(c=>ids.includes(c.id)).reduce((n,c)=>n+c.price_paise,0);
 return <form className="dish-options-form" onSubmit={e=>{e.preventDefault();for(const group of product.optionGroups){const count=group.choices.filter(c=>ids.includes(c.id)).length;if(count<group.min||count>group.max){setError(`Choose ${group.min}–${group.max} options for ${group.name}.`);return;}}onSave({ids,vendorId:product.optionVendorId,extra,menuRevision:product.menuRevision});}}><h2>Make it yours</h2><p>{product.name} · One configuration per product in your bag.</p>{product.optionGroups.map(group=><fieldset key={group.name}><legend>{group.name} · {group.min?'Required':'Optional'}</legend>{group.choices.map(choice=><label className="check-label" key={choice.id}><input type="checkbox" checked={ids.includes(choice.id)} onChange={e=>setIds(previous=>e.target.checked?(group.max===1?[...previous.filter(id=>!group.choices.some(c=>c.id===id)),choice.id]:[...previous,choice.id]):previous.filter(id=>id!==choice.id))}/>{choice.name} {choice.price_paise>0&&`+₹${choice.price_paise/100}`}</label>)}</fieldset>)}<p>Per portion: ₹{product.basePrice+extra/100}</p>{error&&<p role="alert">{error}</p>}<button className="primary">Save options & add to bag</button></form>;
}
