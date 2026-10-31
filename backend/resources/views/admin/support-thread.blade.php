@extends('admin.layout')
@section('title','Support conversation')
@section('content')
<a href="{{ route('admin.support.index') }}">← All concerns</a><h1>#{{ $ticket['id'] }} · {{ $ticket['subject'] }}</h1><p>{{ $ticket['customer_name'] }} · {{ str_replace('_',' ',$ticket['status']) }}</p>
@if($ticket['order_reference'])<p>Order: {{ $ticket['order_reference'] }}</p>@endif
@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
<section class="panel" style="padding:20px"><div id="support-messages" aria-live="polite">@foreach($messages as $message)<article data-message="{{ $message['id'] }}" style="padding:14px;margin-bottom:10px;background:{{ $message['sender_role']==='admin'?'#e8f3ff':'#f6f8fa' }};border-radius:12px"><b>{{ $message['sender_role']==='admin'?'Support':'Customer' }}</b><p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $message['body'] }}</p><small>{{ $message['created_at'] }}</small></article>@endforeach</div>
@if($next_before)<a href="{{ route('admin.support.show',$ticket['id']) }}?before={{ $next_before }}">Older messages</a>@endif
<form method="post" action="{{ route('admin.support.reply',$ticket['id']) }}" style="margin-top:20px">@csrf<input type="hidden" name="request_key" value="{{ old('request_key',(string) Illuminate\Support\Str::uuid()) }}"><label>Reply<textarea name="body" rows="4" maxlength="2000" placeholder="Write your reply to the customer">{{ old('body') }}</textarea></label><label>Status<select name="status">@foreach(['open','in_progress','resolved'] as $state)<option value="{{ $state }}" @selected($ticket['status']===$state)>{{ ucfirst(str_replace('_',' ',$state)) }}</option>@endforeach</select></label><button type="submit">Send / update concern</button></form><p id="support-refresh" role="status">New messages refresh automatically.</p></section>
<script>
(() => {
const target=document.getElementById('support-messages'), info=document.getElementById('support-refresh');
const csrf=document.querySelector('input[name="_token"]').value;
async function mark(id){if(!id)return;await fetch(@json(route('admin.support.read',$ticket['id'])),{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({message_id:id})});}
@if(!$next_before && count($messages)) mark({{ $messages->last()['id'] }}).catch(()=>{}); @endif
let busy=false;
async function refresh(){if(document.visibilityState!=='visible'||busy)return;busy=true;try{const r=await fetch(@json(route('admin.support.show',$ticket['id'])),{headers:{Accept:'application/json'},cache:'no-store'});if(!r.ok)throw Error();const {data}=await r.json();for(const m of data.messages){if(target.querySelector(`[data-message="${m.id}"]`))continue;const a=document.createElement('article');a.dataset.message=m.id;a.style.cssText='padding:14px;background:#f6f8fa;border-radius:12px;margin-bottom:10px';const b=document.createElement('b'),p=document.createElement('p'),small=document.createElement('small');b.textContent=m.sender_role==='admin'?'Support':'Customer';p.textContent=m.body;p.style.cssText='white-space:pre-wrap;overflow-wrap:anywhere';small.textContent=m.created_at;a.append(b,p,small);target.append(a);}await mark(data.messages.at(-1)?.id);info.textContent='Conversation is up to date.';}catch{info.textContent='Could not refresh. Retrying shortly.';}finally{busy=false;}}
@if(!$next_before) setInterval(refresh,10000); @endif
})();
</script>
@endsection
