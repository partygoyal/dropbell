@extends('admin.layout')
@section('title', 'Customer details')
@section('content')
@php $registered = \Illuminate\Support\Carbon::parse($customer->created_at)->timezone('Asia/Kolkata'); @endphp
<div class="page-title vendor-detail-heading">
<a class="edit-link" href="{{ route('admin.customers.index') }}">← All customers</a>
<div class="vendor-identity"><span class="vendor-avatar" aria-hidden="true">{{ mb_substr($customer->name,0,1) }}</span><div><p class="eyebrow">CUSTOMER PROFILE</p><h1>{{ $customer->name }}</h1><p>Customer #{{ $customer->id }} · Registered {{ $registered->format('d M Y') }}</p></div></div>
</div>
<div class="stats customer-summary">
<article><span>Total orders</span><strong>{{ $orders->total() }}</strong><small>All orders placed by this customer</small></article>
<article><span>Paid order value</span><strong>₹{{ number_format($paidValue/100,2) }}</strong><small>Paid orders, excluding sample orders</small></article>
<article><span>Saved addresses</span><strong>{{ $addresses->count() }}</strong><small>Delivery destinations</small></article>
</div>
<div data-order-details>
<nav class="order-tabs" aria-label="Customer sections"><a href="#customer-overview" data-order-tab="customer-overview">Overview</a><a href="#customer-addresses" data-order-tab="customer-addresses">Addresses <span>{{ $addresses->count() }}</span></a><a href="#customer-orders" data-order-tab="customer-orders">Order history <span>{{ $orders->total() }}</span></a></nav>
<section id="customer-overview" data-order-panel="customer-overview" class="order-tab-panel" aria-label="Overview">
<div class="vendor-detail-grid">
<section class="panel product-editor"><h2>Account details</h2><dl class="vendor-facts"><div><dt>Customer name</dt><dd>{{ $customer->name }}</dd></div><div><dt>Mobile number</dt><dd>{{ $customer->phone ?: 'No mobile number' }}</dd></div><div><dt>Customer ID</dt><dd>#{{ $customer->id }}</dd></div><div><dt>Registered</dt><dd>{{ $registered->format('d M Y, H:i') }} IST</dd></div></dl></section>
<section class="panel product-editor"><h2>Default delivery address</h2>@php $defaultAddress = $addresses->firstWhere('is_default',true); @endphp
@if($defaultAddress)<span class="pill">{{ $defaultAddress->label }} · Default</span><p class="customer-address-text"><b>{{ $defaultAddress->name }}</b><br>{{ $defaultAddress->street }}<br>{{ $defaultAddress->city }} — {{ $defaultAddress->postal_code }}</p><p class="location-hint">{{ $defaultAddress->phone }}</p><a class="edit-link" href="#customer-addresses">View all addresses →</a>@else<p class="location-hint">No default address saved. Check the Addresses tab for any saved delivery destinations.</p>@endif
</section>
</div></section>
<section id="customer-addresses" data-order-panel="customer-addresses" class="order-tab-panel" aria-label="Addresses">
<section class="panel product-editor"><h2>Saved addresses</h2><p class="location-hint">Delivery details saved by this customer.</p><div class="customer-address-grid">@forelse($addresses as $address)<article class="customer-address-card"><div class="customer-address-heading"><b>{{ $address->label }}</b>@if($address->is_default)<span class="pill">Default</span>@endif</div><p class="customer-address-text"><b>{{ $address->name }}</b><br>{{ $address->street }}<br>{{ $address->city }} — {{ $address->postal_code }}</p><p class="customer-address-phone">{{ $address->phone }}</p></article>@empty<div class="empty"><b>No addresses saved.</b><p>Saved delivery addresses will appear here.</p></div>@endforelse</div></section>
</section>
<section id="customer-orders" data-order-panel="customer-orders" class="order-tab-panel" aria-label="Order history">
<section class="panel admin-data-table"><div class="panel-heading"><h2>Order history</h2><span>{{ $orders->total() }} orders</span></div><div class="table-wrap"><table data-server-table="customer-orders" data-current-page="{{ $orders->currentPage() }}" data-page-count="{{ $orders->lastPage() }}" data-total="{{ $orders->total() }}" data-previous-page="{{ $orders->previousPageUrl() }}" data-next-page="{{ $orders->nextPageUrl() }}"><thead><tr><th>Reference</th><th>Status</th><th>Total</th><th>Payment</th><th>Actions</th></tr></thead><tbody>@forelse($orders as $order)<tr><td><a class="edit-link" href="{{ route('admin.orders.show',$order->id) }}">{{ $order->reference }}</a><small>{{ \Illuminate\Support\Carbon::parse($order->created_at)->timezone('Asia/Kolkata')->format('d M Y, H:i') }} IST @if($order->is_sample) · Sample order @endif</small></td><td><span class="pill">{{ ucfirst(str_replace('_',' ',$order->status)) }}</span></td><td><b>₹{{ number_format($order->total_paise/100,2) }}</b></td><td>{{ ucfirst($order->payment_status) }}</td><td><a class="edit-link" href="{{ route('admin.orders.show',$order->id) }}">Open order →</a></td></tr>@empty<tr><td colspan="5" class="empty"><b>No orders placed yet.</b><p>Customer orders will appear here once placed.</p></td></tr>@endforelse</tbody></table></div>
@if($orders->hasPages())<div class="admin-table-footer"><span>{{ $orders->firstItem() }}–{{ $orders->lastItem() }} of {{ $orders->total() }}</span><div class="pagination">@if($orders->previousPageUrl())<a href="{{ $orders->previousPageUrl() }}#customer-orders">← Previous</a>@endif<span>Page {{ $orders->currentPage() }} of {{ $orders->lastPage() }}</span>@if($orders->nextPageUrl())<a href="{{ $orders->nextPageUrl() }}#customer-orders">Next →</a>@endif</div></div>@endif
</section></section>
</div>
@endsection
