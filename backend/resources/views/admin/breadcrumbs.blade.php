@php
    $routeName = request()->route()?->getName() ?? '';
    $id = request()->route('id') ?? request()->route('product') ?? request()->route('category') ?? request()->route('sub_category') ?? request()->route('delivery_partner') ?? request()->route('delivery_area') ?? request()->route('promotion');
    $sections = [
        'orders' => ['Orders', 'admin.orders.index', 'Order'],
        'products' => ['Products', 'admin.products.index', 'Product'],
        'customers' => ['Customers', 'admin.customers.index', 'Customer'],
        'vendors' => ['Vendors', 'admin.vendors.index', 'Vendor'],
        'categories' => ['Categories & sub-categories', 'admin.categories.index', 'Main category'],
        'sub-categories' => ['Categories & sub-categories', 'admin.categories.index', 'Sub-category'],
        'delivery-partners' => ['Delivery partners', 'admin.delivery-partners.index', 'Delivery partner'],
        'partner-kyc' => ['Delivery partners', 'admin.delivery-partners.index', 'Delivery partner'],
        'delivery-areas' => ['Delivery areas', 'admin.delivery-areas.index', 'Delivery area'],
        'delivery-zones' => ['Delivery zones', 'admin.delivery-zones.index', 'Zone'],
        'locations' => ['Locations', 'admin.locations', 'Location'],
        'requests' => ['Customer requests', 'admin.requests.index', 'Request'],
        'transactions' => ['Transactions', 'admin.transactions.index', 'Transaction'],
        'profile' => ['My profile', 'admin.profile.edit', 'Profile'],
        'promotions' => ['Coupons & offers', 'admin.promotions.index', 'Promotion'],
        'reports' => ['Reports', 'admin.reports.index', 'Report'],
        'support' => ['Customer support', 'admin.support.index', 'Ticket'],
    ];
    $group = explode('.', $routeName)[1] ?? '';
    $section = $sections[$group] ?? null;
    $crumbs = [['label' => 'Dashboard', 'url' => $routeName === 'admin.dashboard' ? null : route('admin.dashboard')]];
    if ($section) {
        $isIndex = $routeName === $section[1];
        $crumbs[] = ['label' => $section[0], 'url' => $isIndex ? null : route($section[1])];
        if (!$isIndex) {
            if ($group === 'partner-kyc') {
                $crumbs[] = ['label' => 'Partner #'.$id, 'url' => route('admin.delivery-partners.edit', $id)];
                $label = 'Approval & KYC';
            } elseif (str_ends_with($routeName, '.create')) {
                $label = 'Add '.strtolower($section[2]);
            } elseif (str_ends_with($routeName, '.edit')) {
                if (in_array($group, ['orders', 'vendors'])) {
                    $crumbs[] = ['label' => $section[2].' #'.$id, 'url' => route('admin.'.$group.'.show', $id)];
                }
                $label = 'Edit '.strtolower($section[2]).($id ? ' #'.$id : '');
            } elseif ($group === 'sub-categories') {
                $label = 'Add sub-category';
            } else {
                $label = $section[2].($id ? ' #'.$id : ' details');
            }
            $crumbs[] = ['label' => $label, 'url' => null];
        }
    }
@endphp
<nav class="admin-breadcrumbs" aria-label="Breadcrumb">
<ol>@foreach($crumbs as $crumb)<li>@if($crumb['url'])<a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>@else<span aria-current="page">{{ $crumb['label'] }}</span>@endif</li>@endforeach</ol>
</nav>
