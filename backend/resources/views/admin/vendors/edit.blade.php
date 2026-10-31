@extends('admin.layout')
@section('title', 'Edit vendor')
@section('content')
<div class="page-title"><a class="edit-link" href="{{ route('admin.vendors.show',$vendor->id) }}">← Vendor details</a><h1>Edit vendor</h1><p>Update business details and delivery coverage.</p></div>
@if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
<form class="panel product-editor" method="post" action="{{ route('admin.vendors.update',$vendor->id) }}">@csrf @method('PUT')<input type="hidden" name="revision" value="{{ $vendor->revision }}"><div class="editor-grid">
@foreach(['business_name'=>'Business name','pickup_address'=>'Pickup address','city'=>'City','postal_code'=>'Postal code'] as $field=>$label)<label>{{ $label }}<input name="{{ $field }}" required maxlength="{{ $field==='pickup_address' ? 255 : ($field==='postal_code' ? 6 : 120) }}" @if($field==='postal_code') pattern="[1-9][0-9]{5}" inputmode="numeric" @endif value="{{ old($field,$vendor->{$field}) }}"></label>@endforeach
<label class="wide">Service postal codes<textarea required name="service_postal_codes" rows="3" maxlength="2000">{{ old('service_postal_codes',implode(', ',json_decode($vendor->service_postal_codes ?? '[]',true) ?: [])) }}</textarea><small>Separate postal codes with commas, for example 560001, 560002.</small></label></div><div class="editor-actions"><button type="submit">Save vendor</button><a class="edit-link" href="{{ route('admin.vendors.show',$vendor->id) }}">Cancel</a></div></form>
@endsection
