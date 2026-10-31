@extends('vendor.layout')
@section('title','Shop products')
@section('content')
<div class="page-title"><p class="eyebrow">STOCK YOUR DROPBELL SHOP</p><h1>What’s available in your shop?</h1><p>Choose a category, choose a sub-category, then tick the products you sell. Review your prices and save this page’s selection.</p></div>
@if($vendor->status === 'suspended')<p class="notice">Your account is suspended. Contact Dropbell before updating products.</p>
@elseif($vendor->status !== 'approved')<p class="notice">You can prepare your selection now. Customers will see your products after Dropbell approves your business and delivery areas.</p>@endif
<section class="panel vendor-picker" aria-labelledby="vendor-categories-title">
    <div class="panel-heading"><h2 id="vendor-categories-title"><span class="vendor-step">1</span> Choose a category</h2><span>{{ $categories->count() }} categories</span></div>
    <nav class="vendor-choice-grid" aria-label="Product categories">
        @forelse($categories as $category)
        <a @class(['vendor-choice','is-selected'=>$selectedCategory?->id === $category->id]) @if($selectedCategory?->id === $category->id) aria-current="true" @endif href="{{ route('vendor.products',['category'=>$category->name]) }}"><span>{{ $category->name }}</span><span aria-hidden="true">{{ $selectedCategory?->id === $category->id ? '✓' : '→' }}</span></a>
        @empty<p class="empty">Dropbell hasn’t added any categories yet.</p>@endforelse
    </nav>
</section>
<section class="panel vendor-picker" aria-labelledby="vendor-subcategories-title">
    <div class="panel-heading"><h2 id="vendor-subcategories-title"><span class="vendor-step">2</span> Choose a sub-category</h2>@if($selectedCategory)<span>{{ $selectedCategory->name }}</span>@endif</div>
    @if($selectedCategory)
    <nav class="vendor-choice-grid vendor-subcategory-grid" aria-label="Product sub-categories">
        <a @class(['vendor-choice','is-selected'=>!request('sub_category_id') && !request('uncategorized')]) href="{{ route('vendor.products',['category'=>$selectedCategory->name]) }}">All in this category</a>
        @foreach($subCategories as $sub)
        <a @class(['vendor-choice','is-selected'=>request('sub_category_id') == $sub->id]) @if(request('sub_category_id') == $sub->id) aria-current="true" @endif href="{{ route('vendor.products',['category'=>$selectedCategory->name,'sub_category_id'=>$sub->id]) }}">{{ $sub->name }}</a>
        @endforeach
        @if($hasUncategorized)<a @class(['vendor-choice','is-selected'=>request('uncategorized')]) href="{{ route('vendor.products',['category'=>$selectedCategory->name,'uncategorized'=>1]) }}">Other products</a>@endif
    </nav>
    @else<p class="vendor-picker-hint">Select a category above to load its sub-categories.</p>@endif
</section>
<section class="panel vendor-picker" aria-labelledby="vendor-products-title">
    <div class="panel-heading"><h2 id="vendor-products-title"><span class="vendor-step">3</span> Mark your available products</h2><span>{{ $products->total() }} matching products</span></div>
    <form class="vendor-search" method="get" action="{{ route('vendor.products') }}">
        @if($selectedCategory)<input type="hidden" name="category" value="{{ $selectedCategory->name }}">@endif
        @if(request('sub_category_id'))<input type="hidden" name="sub_category_id" value="{{ request('sub_category_id') }}">@endif
        @if(request('uncategorized'))<input type="hidden" name="uncategorized" value="1">@endif
        <label>Find a product<input name="q" value="{{ request('q') }}" placeholder="Search by product name" maxlength="120"></label>
        <label class="vendor-saved-filter"><input type="checkbox" name="selected" value="1" @checked(request('selected'))> My saved products</label>
        <button>Search</button><a class="edit-link" href="{{ route('vendor.products') }}">Reset</a>
    </form>
    @if(!$showProducts)<p class="vendor-picker-hint">Choose a category to load products, or search the whole catalogue above.</p>
    @elseif($products->isEmpty())<p class="vendor-picker-hint">No products match this selection. Try another sub-category or clear your search.</p>
    @else
    <form method="post" action="{{ route('vendor.products.bulk-save',request()->query()) }}" data-vendor-selection>
        @csrf @method('PUT')
        <fieldset @disabled($vendor->status==='suspended') class="vendor-products-fieldset">
            <div class="vendor-selection-tools"><label><input type="checkbox" data-vendor-select-all> Mark all on this page available</label><span data-vendor-selected-count role="status"></span></div>
            <p class="vendor-picker-hint">Tick = available. Untick = unavailable. Prices are prefilled from your saved price or the catalogue; review them before saving. Blank stock means not tracked; zero means sold out.</p>
            <div class="vendor-product-grid">
                @foreach($products as $product)
                @php($row = 'products.'.$product->id)
                <article class="vendor-product-card">
                    <input type="hidden" name="products[{{ $product->id }}][id]" value="{{ $product->id }}">
                    <input type="hidden" name="products[{{ $product->id }}][revision]" value="{{ old($row.'.revision',$product->offer_revision??0) }}">
                    <div class="product-cell">@if($product->image_path)<img src="{{ asset('storage/'.$product->image_path) }}" alt="" loading="lazy">@else<span class="vendor-product-placeholder" aria-hidden="true">▧</span>@endif<div><b>{{ $product->name }}</b><small>{{ $product->pack_size }}</small></div></div>
                    <input type="hidden" name="products[{{ $product->id }}][available]" value="0">
                    <label class="vendor-product-toggle"><input type="checkbox" name="products[{{ $product->id }}][available]" value="1" data-vendor-available @checked(old($row.'.available',$product->vendor_available))><span>Available in my shop<span class="sr-only">: {{ $product->name }}</span></span></label>
                    <div class="vendor-product-fields"><label for="price-{{ $product->id }}">Your price (₹)<input id="price-{{ $product->id }}" type="number" inputmode="decimal" name="products[{{ $product->id }}][price]" min="0.01" max="100000" step="0.01" value="{{ old($row.'.price',(($product->vendor_price ?? $product->price_paise) > 0 ? number_format(($product->vendor_price ?? $product->price_paise)/100,2,'.','') : '')) }}" data-vendor-price></label><label for="stock-{{ $product->id }}">Stock (packs)<input id="stock-{{ $product->id }}" type="number" inputmode="numeric" name="products[{{ $product->id }}][stock_quantity]" min="0" max="1000000" step="1" value="{{ old($row.'.stock_quantity',$product->vendor_stock) }}" placeholder="Not tracked"></label></div>
                    @if($errors->has($row.'.revision') || $errors->has($row.'.price') || $errors->has($row.'.stock_quantity'))<p class="error">{{ $errors->first($row.'.revision') ?: ($errors->first($row.'.price') ?: $errors->first($row.'.stock_quantity')) }}</p>@endif
                </article>
                @endforeach
            </div>
            <div class="vendor-save-bar"><div><b data-vendor-save-state>Review your selection</b><small>Only products on this page are updated. Save before opening another category or page.</small></div><button type="submit" data-vendor-save>Save availability</button></div>
        </fieldset>
    </form>
    @endif
</section>
@if($products->hasPages())<nav class="pagination" aria-label="Product pages">@if($products->previousPageUrl())<a href="{{ $products->previousPageUrl() }}">← Previous</a>@endif<span>Page {{ $products->currentPage() }} of {{ $products->lastPage() }}</span>@if($products->nextPageUrl())<a href="{{ $products->nextPageUrl() }}">Next →</a>@endif</nav>@endif
@endsection
