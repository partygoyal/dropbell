'use client';

import { useState } from 'react';
import Link from 'next/link';
import { customerApi } from '../lib/customer';
import { rupees } from '../lib/purchase.mjs';

const labels = { cancellation: 'Cancel order', return: 'Return items', refund: 'Request refund' };
export function RequestCard({ request, showOrder = false }) {
  return <article className="request-card"><div className="panel-title"><b>{labels[request.type] || request.type} · #{request.id}</b><span className="demo-pill">{request.status === 'returned' ? 'Return received' : request.status}</span></div>{showOrder && <Link className="order-ref" href={`/order/${request.orderId}`}>Order {request.orderId} →</Link>}<p>{request.reason}</p>{request.amount > 0 && <p><b>{request.status === 'requested' ? 'Requested refund' : 'Refund amount'}: {rupees(request.amount)}</b></p>}{request.reviewNote && <p className="review-response"><b>Dropbell update:</b> {request.reviewNote}</p>}<small>Updated {new Date(request.updatedAt).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' })}</small></article>;
}

export default function OrderAftercare({ order, onRefresh }) {
  const [type, setType] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const requests = order.requests || [];
  const financialRequestOpen = requests.some(item => ['return', 'refund'].includes(item.type) && ['requested', 'approved', 'returned'].includes(item.status));
  const types = [
    ...(['received', 'picking'].includes(order.status) ? ['cancellation'] : []),
    ...(order.status === 'delivered' && !financialRequestOpen ? ['return'] : []),
    ...(['delivered', 'cancelled'].includes(order.status) && order.paymentStatus === 'paid' && order.bill.total > order.refunded && !financialRequestOpen ? ['refund'] : []),
  ].filter(value => !requests.some(item => item.type === value));
  const submit = async event => {
    event.preventDefault(); if (busy) return;
    setBusy(true); setError('');
    try {
      await customerApi(`orders/${order.id}/requests`, { method: 'POST', body: { type, reason } });
      setMessage('Request submitted. You can track the review below.'); setType(''); setReason(''); await onRefresh();
    } catch (error) { setError(error.message); } finally { setBusy(false); }
  };
  const invoice = async () => {
    if (busy) return; setBusy(true); setError('');
    try {
      const response = await fetch(`/api/customer/orders/${order.id}/invoice`, { cache: 'no-store' });
      if (!response.ok) { const body = await response.json(); throw new Error(Object.values(body.errors || {}).flat()[0] || body.message || 'Invoice unavailable.'); }
      const url = URL.createObjectURL(await response.blob());
      const anchor = document.createElement('a'); anchor.href = url; anchor.download = `Dropbell-${order.id}-invoice.html`; document.body.appendChild(anchor); anchor.click(); anchor.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
      setMessage('Invoice downloaded. Open it in your browser to print or save as PDF.');
    } catch (error) { setError(error.message); } finally { setBusy(false); }
  };
  return <section className="purchase-panel order-aftercare"><div className="panel-title"><h2>Order help & documents</h2>{order.invoiceAvailable && <button className="secondary" onClick={invoice} disabled={busy}>Download invoice ↓</button>}</div><p>{order.payment}</p>{order.refunded > 0 && <p className="account-message">Refunded: {rupees(order.refunded)}</p>}<div className="account-actions">{types.map(value => <button type="button" className="secondary" key={value} onClick={() => { setType(value); setError(''); setMessage(''); }}>{labels[value]}</button>)}<Link className="text-button" href="/account/transactions">Payment history →</Link></div>{type && <form className="aftercare-form" onSubmit={submit}><h3>{labels[type]}</h3><label>Tell us what happened<textarea required minLength={5} maxLength={2000} rows={3} value={reason} onChange={event => setReason(event.target.value)} placeholder="Describe your reason so our team can help." /></label><p className="bill-note">This request applies to the whole order. Our team will review it and confirm any return and refund amount.</p><div className="account-actions"><button className="primary" type="submit" disabled={busy}>{busy ? 'Submitting…' : 'Submit request'}</button><button className="text-button" type="button" onClick={() => setType('')}>Keep order</button></div></form>}{message && <p className="account-message" role="status">{message}</p>}{error && <p className="form-error" role="alert">{error}</p>}{requests.map(item => <RequestCard key={item.id} request={item} />)}{!order.invoiceAvailable && <p className="bill-note">Your invoice will be available after delivery.</p>}</section>;
}
