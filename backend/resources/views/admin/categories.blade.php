@extends('admin.layout')
@section('title', 'Categories')
@section('content')
<div class="page-title"><p class="eyebrow">CATALOGUE ORGANISATION</p><h1>Categories & sub-categories</h1><p>Create a main category, or choose a parent category and add a sub-category beneath it.</p></div>
@if($errors->any())<div class="error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="category-workspace">
<section class="panel product-editor category-editor">
<h2>{{ $record ? 'Edit '.($kind === 'categories' ? 'main category' : 'sub-category') : 'Add to your catalogue' }}</h2>
@if(!$record)
<nav class="category-mode" aria-label="Category type"><a href="{{ route('admin.categories.index') }}" @if($kind === 'categories') aria-current="page" @endif>New main category</a><a href="{{ route('admin.sub-categories.index') }}" @if($kind === 'sub-categories') aria-current="page" @endif>Add sub-category</a></nav>
@endif
<form method="post" enctype="multipart/form-data" action="{{ $record ? route('admin.'.$kind.'.update', $record->id) : route('admin.'.$kind.'.store') }}">
@csrf @if($record) @method('PUT') @endif
<div class="editor-grid">
@if($kind === 'sub-categories')
<label class="wide">Main category<select required name="category_id"><option value="">Select a main category</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id', $record?->category_id ?? request('category_id')) == $category->id)>{{ $category->name }}{{ $category->active ? '' : ' (inactive)' }}</option>@endforeach</select><small>The sub-category will appear under this main category.</small></label>
@endif
<label class="wide">{{ $kind === 'categories' ? 'Main category name' : 'Sub-category name' }}<input name="name" required maxlength="80" value="{{ old('name', $record?->name) }}" placeholder="{{ $kind === 'categories' ? 'e.g. Fruits & vegetables' : 'e.g. Fresh fruits' }}"></label>
<label class="wide">Short description<textarea name="description" maxlength="300" rows="3" placeholder="Briefly describe what customers will find here.">{{ old('description', $record?->description) }}</textarea><small>Optional · up to 300 characters.</small></label>
<label class="wide">Banner image<input type="file" name="banner" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG or WebP · maximum 5 MB. A wide image works best. Leave blank to keep your current banner.</small></label>
@if($record?->banner_path)
<div class="wide category-banner-current"><img src="{{ Storage::disk('public')->url($record->banner_path) }}" alt="{{ $record->name }} banner"><label class="check-label"><input type="checkbox" name="remove_banner" value="1" @checked(old('remove_banner'))> Remove current banner</label></div>
@endif
</div>
<input type="hidden" name="active" value="0"><label class="check-label"><input type="checkbox" name="active" value="1" @checked(old('active', $record?->active ?? true))> Active and available for shopping</label>
<p class="footnote">Inactive main categories and sub-categories hide their products from shopping. Existing orders are kept.</p>
<div class="editor-actions"><button type="submit">{{ $record ? 'Save changes' : ($kind === 'categories' ? 'Create main category' : 'Create sub-category') }}</button>@if($record)<a class="edit-link" href="{{ route('admin.categories.index') }}">Cancel</a>@endif</div>
</form>
</section>
<section class="panel category-tree"><div class="panel-heading"><div><h2>Your catalogue structure</h2><p class="location-hint">{{ $categories->count() }} main categories · expand a category to manage its sub-categories.</p></div></div>
@forelse($rows as $category)
@php $children = $subCategories->get($category->id, collect()); @endphp
<details class="category-branch" @if($record && (($kind === 'categories' && $record->id === $category->id) || ($kind === 'sub-categories' && $record->category_id === $category->id))) open @endif>
<summary>@if($category->banner_path)<img src="{{ Storage::disk('public')->url($category->banner_path) }}" alt="" loading="lazy">@else<span class="category-placeholder" aria-hidden="true">▦</span>@endif<div><b>{{ $category->name }}</b><small>{{ $children->count() }} sub-categories · {{ $category->active ? 'Active' : 'Inactive' }}</small></div><span class="category-chevron" aria-hidden="true">⌄</span></summary>
<div class="category-branch-body">
@if($category->description)<p>{{ $category->description }}</p>@endif
<div class="category-branch-actions"><a href="{{ route('admin.categories.edit', $category->id) }}">Edit main category</a><a href="{{ route('admin.sub-categories.index', ['category_id' => $category->id]) }}">＋ Add sub-category</a></div>
@forelse($children as $child)
<div class="category-child">@if($child->banner_path)<img src="{{ Storage::disk('public')->url($child->banner_path) }}" alt="" loading="lazy">@endif<div><b>{{ $child->name }}</b><small>{{ $child->active ? 'Active' : 'Inactive' }}</small>@if($child->description)<p>{{ $child->description }}</p>@endif</div><a class="edit-link" href="{{ route('admin.sub-categories.edit', $child->id) }}">Edit →</a></div>
@empty<p class="location-hint">No sub-categories yet. Add one under this main category.</p>@endforelse
</div></details>
@empty<div class="empty"><b>No categories yet</b><p>Create your first main category to get started.</p></div>@endforelse
<div class="pagination category-pagination">@if($rows->previousPageUrl())<a href="{{ $rows->previousPageUrl() }}">← Previous</a>@endif<span>Page {{ $rows->currentPage() }} of {{ $rows->lastPage() }}</span>@if($rows->nextPageUrl())<a href="{{ $rows->nextPageUrl() }}">Next →</a>@endif</div>
</section>
</div>
@endsection
