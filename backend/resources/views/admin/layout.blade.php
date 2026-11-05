<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title', 'Dashboard') · Dropbell Admin</title><link rel="stylesheet" href="{{ asset('admin.css') }}?v=promotions-v1"><script src="{{ asset('admin.js') }}?v=promotions-v1" defer></script><link rel="icon" type="image/svg+xml" href="{{ asset('assets/dropbell-brand-icon.svg') }}"><script src="{{ asset('admin-tables.js') }}?v=promotions-v1" defer></script></head>
<body><div class="admin-shell">
<aside class="sidebar">
<a class="brand" href="{{ route('admin.dashboard') }}"><img class="approved-brand-logo" src="{{ asset('assets/dropbell-logo-approved.svg') }}" width="2010" height="550" alt="Dropbell"><span>ADMIN</span></a>
<p class="nav-label">WORKSPACE</p>
@foreach([['admin.categories.*', 'admin.categories.index', '◈', 'Categories & sub-categories'], ['admin.products.*', 'admin.products.index', '▦', 'Manage products'], ['admin.orders.*', 'admin.orders.index', '▤', 'Manage orders'], ['admin.customers.*', 'admin.customers.index', '♙', 'Registered users'], ['admin.vendors.*', 'admin.vendors.index', '▣', 'Manage vendors'], ['admin.dashboard', 'admin.dashboard', '◫', 'Overview'], ['admin.delivery-partners.*', 'admin.delivery-partners.index', '♧', 'Delivery partners'], ['admin.locations*', 'admin.locations', '⌖', 'Location master'], ['admin.delivery-areas.*', 'admin.delivery-areas.index', '⌖', 'Delivery areas'], ['admin.support.*', 'admin.support.index', '☏', 'Customer support'], ['admin.requests.*', 'admin.requests.index', '↩', 'Returns & refunds'], ['admin.transactions.*', 'admin.transactions.index', '₹', 'Transactions'], ['admin.promotions.*', 'admin.promotions.index', '◉', 'Coupons & offers'], ['admin.reports.*', 'admin.reports.index', '▥', 'Reports']] as [$pattern, $destination, $icon, $label])
<a @class(['selected' => (request()->routeIs($pattern) || ($destination === 'admin.categories.index' && request()->routeIs('admin.sub-categories.*')) || ($destination === 'admin.delivery-partners.index' && request()->routeIs('admin.partner-kyc.*')))]) @if((request()->routeIs($pattern) || ($destination === 'admin.categories.index' && request()->routeIs('admin.sub-categories.*')) || ($destination === 'admin.delivery-partners.index' && request()->routeIs('admin.partner-kyc.*')))) aria-current="page" @endif href="{{ route($destination) }}"><span class="nav-icon" aria-hidden="true">{{ $icon }}</span>{{ $label }}</a>
@endforeach

<a href="{{ route('admin.reviews.index') }}" @class(['selected'=>request()->routeIs('admin.reviews.*')])><span class="nav-icon" aria-hidden="true">★</span>Review moderation</a>
<script>
(() => {
    const sidebar = document.currentScript.closest('.sidebar');
    const key = 'dropbell.admin.sidebar.scroll';
    const save = () => {
        try { sessionStorage.setItem(key, String(sidebar.scrollTop)); } catch {}
    };
    const restore = () => {
        try {
            const position = Number(sessionStorage.getItem(key));
            if (Number.isFinite(position) && position >= 0) sidebar.scrollTop = position;
        } catch {}
    };
    restore();
    sidebar.addEventListener('scroll', save, { passive: true });
    sidebar.addEventListener('click', save, { capture: true });
    window.addEventListener('pagehide', save);
    window.addEventListener('pageshow', restore);
})();
</script>
</aside>
<main class="dashboard"><header>@include('admin.breadcrumbs')
<div class="admin-top-actions">
<details class="admin-account-menu">
<summary><span class="admin-account-avatar" aria-hidden="true">{{ mb_substr(auth()->user()->name,0,1) }}</span><span class="admin-account-name">{{ auth()->user()->name }}</span><span aria-hidden="true">⌄</span></summary>
<div class="admin-account-dropdown"><div class="admin-account-info"><b>{{ auth()->user()->name }}</b><small>{{ auth()->user()->email }}</small><span class="pill">Administrator</span></div><a href="{{ route('admin.profile.edit') }}">My profile</a><a href="{{ route('admin.profile.edit') }}#profile-security">Change password</a><form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit">Logout →</button></form></div>
</details>
<details class="order-notifications" data-notifications-url="{{ route('admin.orders.notifications') }}">
<summary aria-label="Order notifications"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span>Orders</span><span class="notification-count" hidden>0</span></summary>
<div class="notification-panel"><div class="notification-heading"><b>New orders</b><a href="{{ route('admin.orders.index') }}">View all →</a></div><p class="notification-state" role="status">Checking for orders…</p><div class="notification-list"></div></div>
</details></div></header>
@if(session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
@yield('content')
</main></div></body></html>
