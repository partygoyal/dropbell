<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title', 'Dashboard') · Dropbell Admin</title><link rel="stylesheet" href="{{ asset('admin.css') }}"><script src="{{ asset('admin.js') }}" defer></script></head>
<body><div class="admin-shell">
<aside class="sidebar">
<a class="brand" href="{{ route('admin.dashboard') }}">dropbell<span>ADMIN</span></a>
<p class="nav-label">WORKSPACE</p>
@foreach([['admin.dashboard', 'admin.dashboard', '◫', 'Overview'], ['admin.customers.*', 'admin.customers.index', '♙', 'Registered users'], ['admin.orders.*', 'admin.orders.index', '▤', 'Manage orders'], ['admin.requests.*', 'admin.requests.index', '↩', 'Returns & refunds'], ['admin.transactions.*', 'admin.transactions.index', '₹', 'Transactions'], ['admin.products.*', 'admin.products.index', '▦', 'Manage products'], ['admin.vendors.*', 'admin.vendors.index', '▣', 'Manage vendors'], ['admin.categories.*', 'admin.categories.index', '◈', 'Manage categories'], ['admin.sub-categories.*', 'admin.sub-categories.index', '⋮', 'Manage sub-categories'], ['admin.delivery-areas.*', 'admin.delivery-areas.index', '⌖', 'Delivery areas'], ['admin.delivery-partners.*', 'admin.delivery-partners.index', '♧', 'Delivery partners']] as [$pattern, $destination, $icon, $label])
<a @class(['selected' => (request()->routeIs($pattern) || ($destination === 'admin.delivery-partners.index' && request()->routeIs('admin.partner-kyc.*')))]) @if((request()->routeIs($pattern) || ($destination === 'admin.delivery-partners.index' && request()->routeIs('admin.partner-kyc.*')))) aria-current="page" @endif href="{{ route($destination) }}"><span class="nav-icon" aria-hidden="true">{{ $icon }}</span>{{ $label }}</a>
@endforeach
<div class="sidebar-footer"><b>{{ auth()->user()->name }}</b><small>Administrator</small><form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit" class="logout">Sign out →</button></form></div>
</aside>
<main class="dashboard"><header><span>Workspace / @if(request()->routeIs('admin.requests.*')) Customer requests @elseif(request()->routeIs('admin.transactions.*')) Transactions @else {{ request()->routeIs('admin.orders.*') ? 'Orders' : (request()->routeIs('admin.products.*', 'admin.vendors.*') ? (request()->routeIs('admin.vendors.*') ? 'Vendors' : 'Products') : (request()->routeIs('admin.categories.*') ? 'Categories' : (request()->routeIs('admin.sub-categories.*') ? 'Sub-categories' : (request()->routeIs('admin.delivery-partners.*', 'admin.partner-kyc.*') ? 'Delivery partners' : (request()->routeIs('admin.delivery-areas.*') ? 'Delivery areas' : (request()->routeIs('admin.customers.*') ? 'Registered users' : 'Overview')))))) }} @endif</span>
<details class="order-notifications" data-notifications-url="{{ route('admin.orders.notifications') }}">
<summary aria-label="Order notifications"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span>Orders</span><span class="notification-count" hidden>0</span></summary>
<div class="notification-panel"><div class="notification-heading"><b>New orders</b><a href="{{ route('admin.orders.index') }}">View all →</a></div><p class="notification-state" role="status">Checking for orders…</p><div class="notification-list"></div></div>
</details></header>
@if(session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
@yield('content')
</main></div></body></html>
