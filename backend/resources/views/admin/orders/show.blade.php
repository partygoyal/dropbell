@extends('admin.products.layout')
@section('title', 'Order details')
@section('content')
<div class="page-title order-heading">
    <div><a class="edit-link" href="{{ route('admin.orders.index') }}">← All orders</a><p class="eyebrow">ORDER MANAGEMENT</p><h1>Order #{{ $order->id }}</h1><p class="order-reference">{{ $order->reference }}</p><p class="order-placed">Placed {{ \Illuminate\Support\Carbon::parse($order->created_at)->timezone('Asia/Kolkata')->format('d M Y, H:i') }} IST</p></div>
    @if(in_array($order->status, ['received','picking']) && $order->payment_status==='pending')<a class="action" href="{{ route('admin.orders.edit', $order->id) }}">Edit order →</a>@endif
</div>
@if($order->is_sample)<p class="notice"><b>TEST COD ORDER</b> · Sample products and prices. No real delivery or payment collection.</p>@endif
@if($errors->any())<div class="error" role="alert"><b>Could not save your changes.</b><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="stats order-summary">
    <article><span>Order total</span><strong>₹{{ number_format($order->total_paise/100,2) }}</strong><small>{{ $items->sum('quantity') }} items</small></article>
    <article><span>Order status</span><strong class="status-value">{{ ucfirst(str_replace('_',' ',$order->status)) }}</strong><small>Current fulfilment stage</small></article>
    <article><span>Payment</span><strong class="status-value">{{ ucfirst($order->payment_status) }}</strong><small>Pay on delivery</small></article>
    <article><span>Delivery partner</span><strong class="status-value">{{ $partner?->name ?? 'Unassigned' }}</strong><small>{{ $partner?->phone ?? 'Assign a partner in the Delivery tab' }}</small></article>
</div>
<div data-order-details>
<nav class="order-tabs" aria-label="Order sections">
    <a href="#overview" data-order-tab="overview">Overview</a>
    <a href="#delivery" data-order-tab="delivery">Delivery</a>
    <a href="#payment" data-order-tab="payment">Payment</a>
    <a href="#requests" data-order-tab="requests">Customer requests <span>{{ $requests->count() }}</span></a>
    <a href="#activity" data-order-tab="activity">Activity</a>
</nav>
<section id="overview" data-order-panel="overview" class="order-tab-panel" aria-label="Overview">
<section class="panel"><div class="panel-heading"><h2>Order items</h2></div><div class="table-wrap"><table><thead><tr><th>Item</th><th>Vendor / pickup</th><th>Pack</th><th>Quantity</th><th>Unit price</th><th>Amount</th></tr></thead><tbody>@foreach($items as $item)<tr><td>{{ $item->product_name }}</td><td>{{ $item->vendor_name ?: 'Legacy order' }}@if($item->pickup_address) @php $pickup=json_decode($item->pickup_address,true); @endphp<br>{{ $pickup['street'] }}<br>{{ $pickup['city'] }} — {{ $pickup['pin'] }}@endif</td><td>{{ $item->pack_size }}</td><td>{{ $item->quantity }}</td><td>₹{{ number_format($item->unit_price_paise/100,2) }}</td><td><b>₹{{ number_format($item->unit_price_paise*$item->quantity/100,2) }}</b></td></tr>@endforeach</tbody></table></div></section><section class="panel product-editor"><h2>Update order status</h2><p>Current status: <b>{{ $order->status }}</b></p>@php
$nextStatuses = ['received'=>['picking','cancelled'], 'picking'=>['out_for_delivery','cancelled'], 'out_for_delivery'=>['delivered']][$order->status] ?? [];
@endphp
@if($nextStatuses)
<form method="post" action="{{ route('admin.orders.update',$order->id) }}">@csrf @method('PATCH')<input type="hidden" name="revision" value="{{ $order->revision }}">
<div class="editor-grid"><label>Next step<select name="status">@foreach($nextStatuses as $status)<option value="{{ $status }}">{{ ucfirst(str_replace('_',' ',$status)) }}</option>@endforeach</select></label><label>Reason (required for cancellation)<textarea name="reason" minlength="5" maxlength="2000">{{ old('reason') }}</textarea></label></div><p class="footnote">Assign an active partner with admin approval before dispatch. Cancellation is available before dispatch; delivered orders use the return process.</p><button type="submit">Save status →</button></form>
@else<p>This order is final. Review any customer return or refund requests below.</p>@endif</section>
</section>
<section id="delivery" data-order-panel="delivery" class="order-tab-panel" aria-label="Delivery">
<section class="panel product-editor"><h2>Delivery address</h2><p>{{ $address['name'] }}<br>{{ $address['street'] }}<br>{{ $address['city'] }} — {{ $address['pin'] }}<br>{{ $address['phone'] }}</p><p><b>Delivery partner:</b> {{ $partner ? $partner->name.' · '.$partner->phone : 'Unassigned' }}</p>@if($order->admin_notes)<p><b>Internal notes:</b> {{ $order->admin_notes }}</p>@endif<p>Delivery preference: {{ $order->delivery_preference }}</p></section>@if(in_array($order->status, ['received','picking','out_for_delivery']))
<section class="panel product-editor"><h2>Assign delivery partner</h2>@if($partners->isNotEmpty())
<form method="post" action="{{ route('admin.orders.assign', $order->id) }}">@csrf @method('PATCH')<input type="hidden" name="revision" value="{{ $order->revision }}"><div class="editor-grid"><label>Approved partner<select required name="delivery_partner_id"><option value="">Choose a partner</option>@foreach($partners as $availablePartner)<option value="{{ $availablePartner->id }}" @selected($order->delivery_partner_id == $availablePartner->id)>{{ $availablePartner->name }} · {{ $availablePartner->phone }}</option>@endforeach</select></label><label>Assignment reason<textarea required name="reason" minlength="5" maxlength="2000"></textarea></label></div><button>Save assignment →</button></form>
@else<p>No active partners with admin approval. <a class="edit-link" href="{{ route('admin.delivery-partners.index') }}">Review delivery partners →</a></p>@endif</section>@endif
</section>
<section id="payment" data-order-panel="payment" class="order-tab-panel" aria-label="Payment">
<section class="panel product-editor"><h2>Payment breakdown</h2><dl class="order-totals"><div><dt>Items subtotal</dt><dd>₹{{ number_format($order->subtotal_paise/100,2) }}</dd></div><div><dt>Discount</dt><dd>− ₹{{ number_format($order->discount_paise/100,2) }}</dd></div><div><dt>Delivery fee</dt><dd>₹{{ number_format($order->delivery_paise/100,2) }}</dd></div><div class="order-total"><dt>Order total</dt><dd>₹{{ number_format($order->total_paise/100,2) }}</dd></div></dl><p>Pay on delivery · <b>{{ ucfirst($order->payment_status) }}</b></p></section>@if(!$order->is_sample && $order->status==='delivered' && $order->payment_status==='pending')<section class="panel product-editor"><h2>Record pay-on-delivery collection</h2><form method="post" action="{{ route('admin.orders.payment',$order->id) }}">@csrf<div class="editor-grid"><label>Collection receipt / reference<input required name="reference" minlength="3" maxlength="120" placeholder="Cash receipt or collection reference"></label></div><p class="footnote">Confirm only after ₹{{ number_format($order->total_paise/100,2) }} has been collected.</p><button>Confirm payment collected →</button></form></section>@endif
</section>
<section id="requests" data-order-panel="requests" class="order-tab-panel" aria-label="Customer requests">
<section class="panel product-editor"><h2>Customer requests</h2>@forelse($requests as $request)<p><a class="edit-link" href="{{ route('admin.requests.show',$request->id) }}">{{ ucfirst($request->type) }} · {{ ucfirst($request->status) }} →</a></p>@empty<p>No requests for this order.</p>@endforelse</section>
</section>
<section id="activity" data-order-panel="activity" class="order-tab-panel" aria-label="Activity">
<div class="order-activity-grid"><section class="panel product-editor"><h2>Order activity</h2>@forelse($events as $event)<article><b>{{ $event->action }}</b> · {{ $event->admin_name }}<p class="footnote">{{ \Illuminate\Support\Carbon::parse($event->created_at)->timezone('Asia/Kolkata')->format('d M Y, H:i') }} IST</p>@php $details=json_decode($event->details,true); @endphp<p>{{ $details['reason'] ?? (($details['from'] ?? '').' → '.($details['to'] ?? '')) }}</p></article>@empty<p class="footnote">No administrator changes recorded yet.</p>@endforelse</section>
<section class="panel product-editor"><h2>Rider activity</h2>
@forelse($partnerEvents as $event)
    @php $details = json_decode($event->details, true); @endphp
    <article>
        <b>{{ $event->action }}</b> · {{ $event->partner_name }}
        <p class="footnote">{{ \Illuminate\Support\Carbon::parse($event->created_at)->timezone('Asia/Kolkata')->format('d M Y, H:i') }} IST</p>
        @if(isset($details['stop_id']))<p>Pickup: {{ $details['stop_id'] }}</p>@endif
        @if(isset($details['collected_paise']))<p>Collected: ₹{{ number_format($details['collected_paise'] / 100, 2) }}</p>@endif
        @if(!empty($details['receipt']))<p>Receipt: {{ $details['receipt'] }}</p>@endif
    </article>
@empty<p class="footnote">No rider updates recorded yet.</p>@endforelse
</section>
</div></section>
</div>
@endsection
