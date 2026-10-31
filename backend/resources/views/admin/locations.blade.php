@extends('admin.layout')
@section('title', 'Locations')
@section('content')
<div class="page-title"><p class="eyebrow">DELIVERY COVERAGE</p><h1>Locations</h1><p>Set up states, cities and the localities you deliver to.</p></div>
<div class="stats location-summary">
@foreach(['states'=>'States','cities'=>'Cities','localities'=>'Localities'] as $kind=>$label)<article><span>{{ $label }}</span><strong>{{ $$kind->count() }}</strong><small>{{ $$kind->where('active',true)->count() }} active</small></article>@endforeach
</div>
<div data-order-details>
<nav class="order-tabs" aria-label="Location sections">
<a href="#states" data-order-tab="states">States <span>{{ $states->count() }}</span></a>
<a href="#cities" data-order-tab="cities">Cities <span>{{ $cities->count() }}</span></a>
<a href="#localities" data-order-tab="localities">Localities & partners <span>{{ $localities->count() }}</span></a>
</nav>
@if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
@if(session("success"))<p class="notice" role="status">{{ session("success") }}</p>@endif
<details class="location-help"><summary>How delivery coverage and pricing work</summary><p>Customers choose a state, city and locality. A locality and its parent city and state must all be active to accept new orders.</p><p>Choose a flat delivery fee, or calculate it using the distance from your hub to the locality. For example, ₹10/km × 2.5 km = ₹25. Existing orders keep their accepted charges.</p></details>
@foreach(['states'=>'States','cities'=>'Cities','localities'=>'Localities & rider assignments'] as $kind=>$heading)
<section id="{{ $kind }}" data-order-panel="{{ $kind }}" class="panel location-section" aria-label="{{ $heading }}"><div class="panel-heading"><div><h2>{{ $heading }}</h2><p class="location-hint">{{ ['states'=>'Start by adding the states you serve.','cities'=>'Link each city to its state.','localities'=>'Set delivery prices and assign partners for each locality.'][$kind] }}</p></div><span>{{ $$kind->count() }} saved</span></div><div class="location-records">
@foreach(collect([null])->concat($$kind) as $record)
<details class="location-record" @if(!$record) open @endif>
<summary><span><b>{{ $record?->name ?? ['states'=>'Add a state','cities'=>'Add a city','localities'=>'Add a locality'][$kind] }}</b><small>{{ $record ? 'Edit details' : 'Create a new location' }}</small></span>@if($record)<span class="pill">{{ $record->active ? 'Active' : 'Inactive' }}</span>@else<span class="location-add">＋</span>@endif</summary>
<form method="POST" action="{{ route('admin.locations.save',['kind'=>$kind,'id'=>$record?->id]) }}" class="location-form">
@csrf
<div class="editor-grid">
<label>Name<input name="name" value="{{ $record?->name }}" required maxlength="120"></label>
@if($kind==='cities')
<label>State<select name="state_id" required><option value="">Choose a state</option>@foreach($states as $state)<option value="{{ $state->id }}" @selected($record?->state_id===$state->id)>{{ $state->name }}</option>@endforeach</select></label>
@endif
@if($kind==='localities')
<label>City<select name="city_id" required><option value="">Choose a city</option>@foreach($cities as $city)<option value="{{ $city->id }}" @selected($record?->city_id===$city->id)>{{ $states->firstWhere('id',$city->state_id)?->name }} → {{ $city->name }}</option>@endforeach</select></label>
<label>Postal code<input name="postal_code" value="{{ $record?->postal_code }}" pattern="[1-9][0-9]{5}" required></label>
<label>Pricing<select name="pricing_mode"><option value="flat" @selected(($record?->pricing_mode ?? 'locality_per_km')==='flat')>Flat fee</option><option value="locality_per_km" @selected(($record?->pricing_mode ?? 'locality_per_km')==='locality_per_km')>Admin distance × ₹/km</option></select></label>
<label>Hub-to-locality distance (km)<input name="distance_km" type="number" step="0.001" min="0" max="500" value="{{ $record?->distance_meters === null ? '' : number_format($record->distance_meters/1000,3,'.','') }}"></label>
<label>Rate (₹/km)<input name="rate" type="number" step="0.01" min="0" max="100000" value="{{ number_format(($record?->rate_paise_per_km ?? 1000)/100,2,'.','') }}"></label>
<label>Delivery charge (₹)<input name="fee" type="number" step="0.01" min="0" max="100000" value="{{ number_format(($record?->fee_paise ?? 0)/100,2,'.','') }}"></label>
<fieldset class="location-partners wide"><legend>Assigned delivery partners</legend><p class="location-hint">Select the partners who cover this locality.</p><div class="location-partner-options">@foreach($partners as $partner)<label class="location-partner"><input type="checkbox" name="partner_ids[]" value="{{ $partner->id }}" @checked($record && ($assignments[$record->id] ?? collect())->contains('delivery_partner_id',$partner->id))>{{ $partner->name }} {{ $partner->active ? '' : '(inactive)' }}</label>@endforeach</div>@if($partners->isEmpty())<p class="location-hint">No delivery partners yet. Add partners from the Delivery partners menu.</p>@endif</fieldset>
@endif
</div><div class="location-form-footer"><label class="location-active"><input type="checkbox" name="active" value="1" @checked($record?->active ?? true)> Active</label>
<button type="submit">{{ $record ? 'Save changes' : 'Add '.['states'=>'state','cities'=>'city','localities'=>'locality'][$kind] }}</button></div>
</form></details>
@endforeach
</div></section>
@endforeach
</div>
@endsection
