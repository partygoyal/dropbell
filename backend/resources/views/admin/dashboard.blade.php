@extends('admin.layout')
@section('title', 'Dashboard')
@section('content')
<div class="page-title"><p class="eyebrow">BUSINESS AT A GLANCE</p><h1>Dashboard</h1><p>Order, catalogue and marketplace statistics.</p></div>
<section class="dashboard-stat-section" aria-label="Orders and customers"><h2>Orders & customers</h2><div class="stats">
<article><span>Total orders</span><strong>{{ $orders }}</strong><small>{{ $sampleOrders }} test orders included</small></article>
<article><span>Open orders</span><strong>{{ $pendingOrders }}</strong><small>Awaiting completion</small></article>
<article><span>Paid order value</span><strong>₹{{ number_format($sales/100,2) }}</strong><small>Paid orders, excluding samples</small></article>
<article><span>Registered users</span><strong>{{ $customers }}</strong><small>Customer accounts</small></article>
</div></section>
<section class="dashboard-stat-section" aria-label="Catalogue and inventory"><h2>Catalogue & inventory</h2><div class="stats">
<article><span>Catalogue products</span><strong>{{ $products }}</strong><small>{{ $activeProducts }} active products</small></article>
<article><span>On storefront</span><strong>{{ $storefrontProducts }}</strong><small>{{ $saleableProducts }} orderable · {{ $sampleProducts }} sample previews</small></article>
<article><span>Real vendor stock</span><strong>{{ $vendorUnits }}</strong><small>Available packs · {{ $untrackedOffers }} offers without stock tracking</small></article>
<article><span>Sample quantities</span><strong>{{ $sampleUnits }}</strong><small>Illustrative packs, excluded from real inventory</small></article>
</div></section>
<section class="dashboard-stat-section" aria-label="Vendors and partner approvals"><h2>Vendors & partner approvals</h2><div class="stats">
<article><span>Registered vendors</span><strong>{{ $vendorCount }}</strong><small>Current vendor accounts</small></article>
<article><span>Pending vendors</span><strong>{{ $pendingVendors }}</strong><small>Awaiting admin approval</small></article>
<article><span>Pending partner reviews</span><strong>{{ $pendingKyc }}</strong><small>Submissions awaiting review</small></article>
</div></section>
@endsection
