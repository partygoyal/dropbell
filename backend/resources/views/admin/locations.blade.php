@extends('admin.layout')
@section('content')
<h1>Location master</h1>
@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
@if(session("success"))<p role="status">{{ session("success") }}</p>@endif
<p>Customers select State → City → Locality. Only active localities with active parents accept new orders. No GPS or map service is used. Per-km pricing uses the hub-to-locality distance entered by admin, not the exact distance to each home. ₹10/km × 2.5 km = ₹25. Existing orders retain their accepted charges.</p>
@foreach(['states'=>'States','cities'=>'Cities','localities'=>'Localities & rider assignments'] as $kind=>$heading)
<section class="panel" style="padding:20px;margin-bottom:24px"><h2>{{ $heading }}</h2>
@foreach(collect([null])->concat($$kind) as $record)
<form method="POST" action="{{ route('admin.locations.save',['kind'=>$kind,'id'=>$record?->id]) }}" style="border-top:1px solid #ddd;padding:16px 0;display:flex;gap:12px;flex-wrap:wrap;align-items:end">
@csrf
<strong>{{ $record ? 'Edit #'.$record->id : 'Add new' }}</strong>
<label>Name<input name="name" value="{{ $record?->name }}" required maxlength="120"></label>
@if($kind==='cities')
<label>State<select name="state_id" required>@foreach($states as $state)<option value="{{ $state->id }}" @selected($record?->state_id===$state->id)>{{ $state->name }}</option>@endforeach</select></label>
@endif
@if($kind==='localities')
<label>City<select name="city_id" required>@foreach($cities as $city)<option value="{{ $city->id }}" @selected($record?->city_id===$city->id)>{{ $states->firstWhere('id',$city->state_id)?->name }} → {{ $city->name }}</option>@endforeach</select></label>
<label>Postal code<input name="postal_code" value="{{ $record?->postal_code }}" pattern="[1-9][0-9]{5}" required></label>
<label>Pricing<select name="pricing_mode"><option value="flat" @selected(($record?->pricing_mode ?? 'locality_per_km')==='flat')>Flat fee</option><option value="locality_per_km" @selected(($record?->pricing_mode ?? 'locality_per_km')==='locality_per_km')>Admin distance × ₹/km</option></select></label>
<label>Hub-to-locality distance (km)<input name="distance_km" type="number" step="0.001" min="0" max="500" value="{{ $record?->distance_meters === null ? '' : number_format($record->distance_meters/1000,3,'.','') }}"></label>
<label>Rate (₹/km)<input name="rate" type="number" step="0.01" min="0" max="100000" value="{{ number_format(($record?->rate_paise_per_km ?? 1000)/100,2,'.','') }}"></label>
<label>Delivery charge (₹)<input name="fee" type="number" step="0.01" min="0" max="100000" value="{{ number_format(($record?->fee_paise ?? 0)/100,2,'.','') }}"></label>
<fieldset><legend>Assigned delivery partners (choose multiple)</legend>@foreach($partners as $partner)<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="partner_ids[]" value="{{ $partner->id }}" @checked($record && ($assignments[$record->id] ?? collect())->contains('delivery_partner_id',$partner->id))>{{ $partner->name }} {{ $partner->active ? '' : '(inactive)' }}</label>@endforeach</fieldset>
@endif
<label><input type="checkbox" name="active" value="1" @checked($record?->active ?? true)> Active</label>
<button type="submit">{{ $record ? 'Save changes' : 'Add' }}</button>
</form>
@endforeach
</section>
@endforeach
@endsection
