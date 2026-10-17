'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { customerApi } from '../lib/customer';
import { rupees } from '../lib/purchase.mjs';
import { RequestCard } from './order-aftercare';

export default function AccountActivity({ view }) {
  const [rows, setRows] = useState([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState('');
  const load = async () => { try { const { data } = await customerApi(view); setRows(data); setError(''); } catch (error) { setError(error.message); } finally { setLoaded(true); } };
  useEffect(() => { load(); }, [view]);
  return <section className="purchase-panel"><div className="panel-title"><h2>{view === 'transactions' ? 'Payments & refunds' : 'Cancellations, returns & refunds'}</h2><button className="text-button" onClick={load}>Refresh ↻</button></div>{error && <p className="form-error" role="alert">{error}</p>}{!loaded ? <p role="status">Loading your records…</p> : !rows.length ? <div className="account-empty"><h3>{view === 'transactions' ? 'No transactions yet.' : 'No requests yet.'}</h3><p>{view === 'transactions' ? 'Pay-on-delivery orders and completed refunds will appear here.' : 'Open an order to request a cancellation, return, or refund.'}</p><Link className="secondary" href="/account/orders">View my orders →</Link></div> : view === 'requests' ? rows.map(row => <RequestCard key={row.id} request={row} showOrder />) : <div className="customer-transactions">{rows.map(row => <article className="request-card" key={row.id}><div className="panel-title"><b>{row.type === 'refund' ? 'Refund' : 'Pay on delivery'}</b><strong>{rupees(row.amount)}</strong></div><span className="demo-pill">{row.status === 'pending' ? 'Awaiting collection' : row.status === 'voided' ? 'Cancelled · no collection due' : 'Completed'}</span><p><Link href={`/order/${row.orderId}`}>Order {row.orderId} →</Link></p><p>Method: {row.method.replaceAll('_', ' ').toUpperCase()}{row.reference && <><br />Reference: {row.reference}</>}</p><small>{new Date(row.updatedAt).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' })}</small></article>)}</div>}<p className="bill-note">Only payments recorded as collected and refunds recorded as paid are shown as completed.</p></section>;
}
