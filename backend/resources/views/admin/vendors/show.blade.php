@extends('admin.layout')
@section('title', 'Vendor details')
@section('content')
<div class="page-title vendor-detail-heading">
    <a class="edit-link" href="{{ route('admin.vendors.index') }}">← All vendors</a>
    <div class="vendor-identity"><span class="vendor-avatar" aria-hidden="true">{{ mb_substr($vendor->business_name ?: $vendor->owner_name, 0, 1) }}</span><div><p class="eyebrow">VENDOR MANAGEMENT</p><h1>{{ $vendor->business_name ?: $vendor->owner_name }}</h1><p>Vendor #{{ $vendor->id }} · {{ $vendor->owner_name }}</p></div><span class="vendor-status vendor-status-{{ $vendor->status }}">{{ ucfirst($vendor->status) }}</span></div>
</div>
@if($errors->any())<div class="error" role="alert"><b>Could not save the review.</b><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@php $postalCodes = json_decode($vendor->service_postal_codes ?? '[]', true) ?: []; @endphp
<div class="stats vendor-detail-summary">
    <article><span>Marketplace access</span><strong class="status-value">{{ ucfirst($vendor->status) }}</strong><small>{{ $vendor->status === 'approved' ? 'Approved to sell' : 'Review access below' }}</small></article>
    <article><span>Selected products</span><strong>{{ $offers->total() }}</strong><small>Products in this vendor’s catalogue</small></article>
    <article><span>Delivery coverage</span><strong>{{ count($postalCodes) }}</strong><small>Service postal codes</small></article>
</div>
<div data-order-details>
@if(!$vendor->archived_at)<div class="editor-actions"><a class="action" href="{{ route('admin.vendors.edit',$vendor->id) }}">Edit vendor details →</a></div>@endif
<nav class="order-tabs" aria-label="Vendor sections"><a href="#vendor-overview" data-order-tab="vendor-overview">Shop details</a><a href="#vendor-products" data-order-tab="vendor-products">Products <span>{{ $offers->total() }}</span></a><a href="#vendor-review" data-order-tab="vendor-review">Review & access</a></nav>
<section id="vendor-overview" data-order-panel="vendor-overview" class="order-tab-panel" aria-label="Shop details">
<div class="vendor-detail-grid">
<section class="panel product-editor"><h2>Business & contact</h2><dl class="vendor-facts"><div><dt>Business name</dt><dd>{{ $vendor->business_name ?: 'Not entered' }}</dd></div><div><dt>Owner</dt><dd>{{ $vendor->owner_name }}</dd></div><div><dt>Email address</dt><dd>{{ $vendor->email ?: 'Not entered' }}</dd></div><div><dt>Phone number</dt><dd>{{ $vendor->phone ?: 'Not entered' }}</dd></div></dl></section>
<section class="panel product-editor"><h2>Pickup & service area</h2><p class="vendor-section-label">Pickup address</p><p class="vendor-pickup">{{ $vendor->pickup_address ?: 'Pickup address not entered' }}<br>{{ $vendor->city }} {{ $vendor->postal_code }}</p><p class="vendor-section-label">Service postal codes</p><div class="vendor-postal-codes">@forelse($postalCodes as $postalCode)<span>{{ $postalCode }}</span>@empty<p class="location-hint">No service postal codes entered yet.</p>@endforelse</div></section>
</div>
@if($vendor->review_note)<section class="panel product-editor"><h2>Latest review note</h2><p class="vendor-note">{{ $vendor->review_note }}</p></section>@endif
</section>
<section id="vendor-products" data-order-panel="vendor-products" class="order-tab-panel" aria-label="Products">
<section class="panel"><div class="panel-heading"><div><h2>Selected products</h2><p class="location-hint">Vendor prices, availability and stock for this shop.</p></div><span>{{ $offers->total() }} products</span></div><div class="table-wrap"><table data-server-table="vendor-products" data-current-page="{{ $offers->currentPage() }}" data-page-count="{{ $offers->lastPage() }}" data-total="{{ $offers->total() }}" data-previous-page="{{ $offers->previousPageUrl() }}" data-next-page="{{ $offers->nextPageUrl() }}"><thead><tr><th>Product</th><th>Vendor price</th><th>Availability</th><th>Stock (packs)</th><th>Actions</th></tr></thead><tbody>@forelse($offers as $offer)<tr><td><b>{{ $offer->name }}</b><small class="vendor-pack-size">{{ $offer->pack_size }}</small></td><td>₹{{ number_format($offer->price_paise/100,2) }}</td><td><span class="vendor-availability {{ $offer->available ? 'is-available' : '' }}">{{ $offer->available ? 'Available' : 'Unavailable' }}</span></td><td>{{ $offer->stock_quantity ?? 'Not tracked' }}</td><td><a class="edit-link" href="{{ route('admin.products.edit',$offer->product_id) }}">Edit catalogue product</a></td></tr>@empty<tr><td colspan="5" class="empty"><b>No products selected yet</b><p>This vendor’s product selections will appear here.</p></td></tr>@endforelse</tbody></table></div></section>
@if($offers->hasPages())<div class="pagination">@if($offers->previousPageUrl())<a href="{{ $offers->previousPageUrl() }}#vendor-products">← Previous</a>@endif<span>Page {{ $offers->currentPage() }} of {{ $offers->lastPage() }}</span>@if($offers->nextPageUrl())<a href="{{ $offers->nextPageUrl() }}#vendor-products">Next →</a>@endif</div>@endif
</section>
<section id="vendor-review" data-order-panel="vendor-review" class="order-tab-panel" aria-label="Review and access">
<section class="panel product-editor"><h2>Review marketplace access</h2><p class="location-hint">Approve this vendor to sell, or suspend access while changes are needed.</p>
@if($vendor->review_note)<div class="vendor-previous-review"><b>Previous review note</b><p>{{ $vendor->review_note }}</p></div>@endif
@if(!$vendor->archived_at)<form method="post" action="{{ route('admin.vendors.review',$vendor->id) }}">@csrf @method('PATCH')<input type="hidden" name="revision" value="{{ $vendor->revision }}"><div class="editor-grid vendor-review-fields"><label>Decision<select name="status" required><option value="">Choose a decision</option><option value="approved" @selected(old('status') === 'approved')>Approve marketplace access</option><option value="suspended" @selected(old('status') === 'suspended')>Suspend / changes required</option></select></label><label class="wide">Review note<textarea name="review_note" rows="4" maxlength="1000" placeholder="Add feedback or explain any changes needed.">{{ old('review_note') }}</textarea><small>A reason is required when suspending access.</small></label></div><div class="editor-actions"><button type="submit">Save review →</button></div></form>@else<p class="notice">This vendor has been deleted. Historical details remain available for reference.</p>@endif
</section></section>
</div>
@endsection
