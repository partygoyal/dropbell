@php
$weeklyHours = old('hours', json_decode($vendor->opening_hours ?? 'null', true) ?? array_fill(0, 7, ['enabled'=>true, 'opens'=>'09:00', 'closes'=>'22:00']));
@endphp
<section class="panel vendor-operations-panel">
<div class="panel-heading"><h2>Hours & order availability</h2><span>Times use India Standard Time · IST</span></div>
<p>Pause new orders when busy. Existing orders remain available to prepare and deliver.</p>
<form method="post" action="{{ route('vendor.operations.save') }}" class="product-editor">
@csrf @method('PUT')
<input type="hidden" name="revision" value="{{ $vendor->revision }}">
<fieldset @disabled($vendor->status !== 'approved')>
<div class="editor-grid">
<label>Taking new orders<select name="operating_mode"><option value="open" @selected(old('operating_mode',$vendor->operating_mode)==='open')>Open · follow opening hours</option><option value="busy" @selected(old('operating_mode',$vendor->operating_mode)==='busy')>Busy · pause new orders</option><option value="closed" @selected(old('operating_mode',$vendor->operating_mode)==='closed')>Closed · pause new orders</option></select></label>
<label>Usual preparation time (minutes)<input type="number" name="preparation_minutes" min="5" max="180" required value="{{ old('preparation_minutes',$vendor->preparation_minutes) }}"></label>
</div>
<input type="hidden" name="schedule_enabled" value="0"><label class="check-label"><input type="checkbox" name="schedule_enabled" value="1" @checked(old('schedule_enabled',$vendor->opening_hours !== null))>Use weekly opening hours</label>
<p class="footnote">Without a schedule, Open accepts orders all day. For overnight service, set a closing time earlier than the opening time—for example, 18:00–02:00.</p>
<details><summary>Weekly opening times</summary><div class="table-wrap"><table><thead><tr><th>Day</th><th>Open</th><th>From</th><th>Until</th></tr></thead><tbody>
@foreach(['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $index=>$day)
<tr><td>{{ $day }}</td><td><input type="hidden" name="hours[{{ $index }}][enabled]" value="0"><input type="checkbox" name="hours[{{ $index }}][enabled]" value="1" aria-label="{{ $day }} open" @checked($weeklyHours[$index]['enabled'] ?? false)></td><td><input type="time" required name="hours[{{ $index }}][opens]" aria-label="{{ $day }} opening time" value="{{ $weeklyHours[$index]['opens'] ?? '09:00' }}"></td><td><input type="time" required name="hours[{{ $index }}][closes]" aria-label="{{ $day }} closing time" value="{{ $weeklyHours[$index]['closes'] ?? '22:00' }}"></td></tr>
@endforeach
</tbody></table></div></details>
<button class="action" type="submit">Save hours & availability</button>
</fieldset>
</form>
</section>
