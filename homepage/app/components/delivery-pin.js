'use client';
import { useEffect, useState } from 'react';
import { fetchJson } from '../lib/http.mjs';
export default function DeliveryLocation({value = {}, onChange, disabled=false}) {
 const [states,setStates]=useState([]), [error,setError]=useState(''), [loading,setLoading]=useState(true);
 const [stateId,setState]=useState(''),[cityId,setCity]=useState('');
 const load=()=>{setLoading(true);setError('');fetchJson('/api/locations',{cache:'no-store'}).then(body=>{if(!Array.isArray(body.data))throw new Error('Delivery locations are temporarily unavailable.');return body.data;}).then(rows=>{setStates(rows);for(const s of rows)for(const c of s.cities)if(c.localities.some(l=>String(l.id)===String(value.locality_id))){setState(String(s.id));setCity(String(c.id));}}).catch(e=>setError(e.message)).finally(()=>setLoading(false));};
 useEffect(load,[]);
 useEffect(()=>{if(!value.locality_id)return;for(const s of states)for(const c of s.cities)if(c.localities.some(l=>String(l.id)===String(value.locality_id))){setState(String(s.id));setCity(String(c.id));}},[value.locality_id,states]);
 const state=states.find(s=>String(s.id)===stateId),city=state?.cities.find(c=>String(c.id)===cityId);
 if(loading)return <p role="status">Loading delivery locations…</p>;
 if(error)return <p role="alert">{error} <button type="button" onClick={load}>Try again</button></p>;
 if(!states.length)return <p>No delivery locations are available yet. Please check again later.</p>;
 return <section className="delivery-pin-picker"><h3>Delivery location</h3><p className="bill-note">We deliver only in the localities listed below.</p><div className="address-fields">
 <label>State<select value={stateId} disabled={disabled} required onChange={e=>{setState(e.target.value);setCity('');onChange({state_id:null,city_id:null,locality_id:null,city:'',pin:''});}}><option value="">Select State</option>{states.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
 <label>City<select value={cityId} disabled={disabled||!state} required onChange={e=>{setCity(e.target.value);onChange({state_id:null,city_id:null,locality_id:null,city:'',pin:''});}}><option value="">Select City</option>{state?.cities.map(c=><option key={c.id} value={c.id}>{c.name}</option>)}</select></label>
 <label>Locality / Area<select value={value.locality_id||''} disabled={disabled||!city} required onChange={e=>{const l=city.localities.find(l=>String(l.id)===e.target.value);onChange(l?{state_id:state.id,city_id:city.id,locality_id:l.id,state:state.name,city:city.name,locality:l.name,pin:l.postal_code}:{locality_id:null,pin:''});}}><option value="">Select Locality</option>{city?.localities.map(l=><option key={l.id} value={l.id}>{l.name}</option>)}</select></label>
 </div></section>;
}
