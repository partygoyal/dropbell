@extends('admin.layout')
@section('title','Customer support')
@section('content')
<h1>Customer support</h1><p>Reply to customer concerns and keep them informed until the issue is resolved.</p>
<form method="get"><label>Status <select name="status"><option value="">All</option>@foreach(['open','in_progress','resolved'] as $state)<option value="{{ $state }}" @selected($status===$state)>{{ ucfirst(str_replace('_',' ',$state)) }}</option>@endforeach</select></label><button>Filter</button></form>
<section class="panel" style="padding:20px;margin-top:20px"><table><thead><tr><th>Concern</th><th>Customer</th><th>Status</th><th>Unread</th><th>Updated</th></tr></thead><tbody>@forelse($tickets as $ticket)<tr><td><a href="{{ route('admin.support.show',$ticket['id']) }}">#{{ $ticket['id'] }} · {{ $ticket['subject'] }}</a></td><td>{{ $ticket['customer_name'] }}</td><td>{{ str_replace('_',' ',$ticket['status']) }}</td><td>{{ $ticket['unread'] }}</td><td>{{ $ticket['updated_at'] }}</td></tr>@empty<tr><td colspan="5">No support concerns yet.</td></tr>@endforelse</tbody></table>{{ $tickets->withQueryString()->links() }}</section>
<script>setTimeout(() => { if(document.visibilityState==='visible') location.reload(); }, 30000);</script>
@endsection
