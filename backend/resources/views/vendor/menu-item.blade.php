@extends('vendor.layout')
@section('title','Dish options & dietary labels')
@section('content')
<h1>{{ $product->name }}</h1><p>Option prices are added to your base price per portion. Stock is shared across all options. Dietary information must reflect your kitchen and ingredients.</p>
<form class="panel product-editor" method="post" action="{{ route('vendor.menu.save',$product->id) }}">@csrf @method('PUT')<input type="hidden" name="revision" value="{{ $offer->revision }}">
<div class="editor-grid"><label>Dietary label<select name="dietary_label">@foreach(['unspecified'=>'Not specified','vegetarian'=>'Vegetarian','vegan'=>'Vegan','non_vegetarian'=>'Non-vegetarian'] as $value=>$label)<option value="{{ $value }}" @selected(old('dietary_label',$offer->dietary_label)===$value)>{{ $label }}</option>@endforeach</select></label><label>Contains / may contain allergens (comma separated)<input name="allergens" maxlength="500" value="{{ old('allergens',implode(', ',json_decode($offer->allergens??'[]',true))) }}" placeholder="For example: milk, wheat, peanuts"></label></div>
<p>Not specifying allergens does not mean allergen-free. Customers should contact your kitchen about allergies.</p>
@php($groups=old('groups',json_decode($offer->option_groups??'[]',true)))
@for($g=0;$g<3;$g++)
@php($group=$groups[$g]??[])
<fieldset><legend>Option group {{ $g+1 }} — {{ ['size','spice level','extras'][$g] }} or your own choice</legend><label>Group name<input name="groups[{{ $g }}][name]" maxlength="60" value="{{ $group['name']??'' }}" placeholder="Leave blank to disable this group"></label>
<label class="check-label"><input type="checkbox" name="groups[{{ $g }}][required]" value="1" @checked($group['required']??(($group['min']??0)>0))>Customer must choose an option</label><label class="check-label"><input type="checkbox" name="groups[{{ $g }}][multiple]" value="1" @checked($group['multiple']??(($group['max']??1)>1))>Allow multiple selections</label>
<div class="editor-grid">@for($c=0;$c<6;$c++)@php($choice=$group['choices'][$c]??[])<label>Choice {{ $c+1 }}<input name="groups[{{ $g }}][choices][{{ $c }}][name]" maxlength="60" value="{{ $choice['name']??'' }}"></label><label>Extra price (₹)<input type="number" step="0.01" min="0" max="10000" name="groups[{{ $g }}][choices][{{ $c }}][price]" value="{{ $choice['price']??number_format(($choice['price_paise']??0)/100,2,'.','') }}"></label>@endfor</div></fieldset>
@endfor
<button>Save dish options & labels</button></form>
@endsection
