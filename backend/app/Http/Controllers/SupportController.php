<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupportController extends Controller
{
    private function tickets(Request $request): Builder
    {
        $query = DB::table('support_tickets');
        if (! $request->user()->is_admin) {
            $query->where('user_id', $request->user()->id);
        }

        return $query;
    }

    private function present(object $ticket, bool $admin): array
    {
        $unread = DB::table('support_messages')->where('ticket_id', $ticket->id)->where('id', '>', $admin ? $ticket->admin_read_id : $ticket->customer_read_id)->where('sender_role', $admin ? 'customer' : 'admin')->count();

        return ['id' => $ticket->id, 'subject' => $ticket->subject, 'status' => $ticket->status, 'order_reference' => $ticket->order_id ? DB::table('orders')->where('id', $ticket->order_id)->value('reference') : null, 'unread' => $unread, 'created_at' => $ticket->created_at, 'updated_at' => $ticket->updated_at, ...($admin ? ['customer_name' => DB::table('users')->where('id', $ticket->user_id)->value('name')] : [])];
    }

    public function index(Request $request): JsonResponse|View
    {
        $input = $request->validate(['status' => 'nullable|in:open,in_progress,resolved']);
        $query = $this->tickets($request);
        if (! empty($input['status'])) {
            $query->where('status', $input['status']);
        }
        $query->orderByDesc('updated_at')->orderByDesc('id');
        $rows = $request->user()->is_admin && ! $request->expectsJson() ? $query->adminPaginate('support') : $query->paginate(20);
        if ($request->user()->is_admin && ! $request->expectsJson()) {
            return view('admin.support', ['tickets' => $rows->through(fn ($t) => $this->present($t, true)), 'status' => $input['status'] ?? '']);
        }

        return response()->json(['data' => $rows->map(fn ($t) => $this->present($t, (bool) $request->user()->is_admin)), 'next_page' => $rows->hasMorePages() ? $rows->currentPage() + 1 : null])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate(['request_key' => 'required|uuid', 'subject' => 'required|string|min:3|max:120', 'body' => 'required|string|min:5|max:2000', 'order_reference' => 'nullable|string|max:80']);
        abort_if($request->user()->is_sample_guest, 403, 'Sign in with a customer account to contact support.');
        $ticket = DB::transaction(function () use ($request, $input) {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $existing = DB::table('support_tickets')->where('user_id', $request->user()->id)->where('request_key', $input['request_key'])->first();
            if ($existing) {
                return $existing;
            }
            $order = empty($input['order_reference']) ? null : DB::table('orders')->where('reference', $input['order_reference'])->where('user_id', $request->user()->id)->first();
            if (! empty($input['order_reference']) && ! $order) {
                throw ValidationException::withMessages(['order_reference' => 'Select one of your own orders.']);
            }
            $id = DB::table('support_tickets')->insertGetId(['user_id' => $request->user()->id, 'order_id' => $order?->id, 'request_key' => $input['request_key'], 'subject' => $input['subject'], 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            $message = DB::table('support_messages')->insertGetId(['ticket_id' => $id, 'sender_id' => $request->user()->id, 'sender_role' => 'customer', 'request_key' => $input['request_key'], 'body' => $input['body'], 'created_at' => now(), 'updated_at' => now()]);
            DB::table('support_tickets')->where('id', $id)->update(['customer_read_id' => $message]);

            return DB::table('support_tickets')->find($id);
        });

        return response()->json(['data' => $this->present($ticket, false)], 201);
    }

    public function show(Request $request, int $id): JsonResponse|View
    {
        $input = $request->validate(['before' => 'nullable|integer|min:1']);
        $ticket = $this->tickets($request)->where('id', $id)->first();
        abort_unless($ticket, 404);
        $query = DB::table('support_messages')->where('ticket_id', $id);
        if (! empty($input['before'])) {
            $query->where('id', '<', $input['before']);
        }
        $messages = $query->orderByDesc('id')->limit(51)->get();
        $more = $messages->count() > 50;
        $messages = $messages->take(50)->reverse()->values()->map(fn ($m) => ['id' => $m->id, 'sender_role' => $m->sender_role, 'body' => $m->body, 'created_at' => $m->created_at]);
        $data = ['ticket' => $this->present($ticket, (bool) $request->user()->is_admin), 'messages' => $messages, 'next_before' => $more ? $messages->first()['id'] : null];
        if ($request->user()->is_admin && ! $request->expectsJson()) {
            return view('admin.support-thread', $data);
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function reply(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $admin = (bool) $request->user()->is_admin;
        $input = $request->validate(['request_key' => 'required|uuid', 'body' => $admin ? 'nullable|required_without:status|string|min:1|max:2000' : 'required|string|min:1|max:2000', ...($admin ? ['status' => 'sometimes|in:open,in_progress,resolved'] : [])]);
        DB::transaction(function () use ($request, $id, $input, $admin): void {
            $ticket = $this->tickets($request)->where('id', $id)->lockForUpdate()->first();
            abort_unless($ticket, 404);
            $role = $admin ? 'admin' : 'customer';
            $exists = DB::table('support_messages')->where('ticket_id', $id)->where('sender_role', $role)->where('request_key', $input['request_key'])->exists();
            if ($exists) {
                return;
            }
            $body = trim($input['body'] ?? '');
            if ($body === '' && ! isset($input['status'])) {
                throw ValidationException::withMessages(['body' => 'Enter a reply or select a status.']);
            }
            $message = DB::table('support_messages')->insertGetId(['ticket_id' => $id, 'sender_id' => $request->user()->id, 'sender_role' => $role, 'request_key' => $input['request_key'], 'body' => $body === '' ? 'Status changed to '.str_replace('_', ' ', $input['status']).'.' : $body, 'created_at' => now(), 'updated_at' => now()]);
            $status = $admin ? ($input['status'] ?? ($ticket->status === 'resolved' ? 'resolved' : 'in_progress')) : ($ticket->status === 'resolved' ? 'open' : $ticket->status);
            DB::table('support_tickets')->where('id', $id)->update(['status' => $status, $admin ? 'admin_read_id' : 'customer_read_id' => $message, 'updated_at' => now()]);
        });
        if ($admin && ! $request->expectsJson()) {
            return redirect()->route('admin.support.show', $id)->with('status', 'Support concern updated.');
        }

        return response()->json(['message' => 'Message saved.']);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $input = $request->validate(['message_id' => 'required|integer|min:1']);
        DB::transaction(function () use ($request, $id, $input): void {
            $ticket = $this->tickets($request)->where('id', $id)->lockForUpdate()->first();
            abort_unless($ticket, 404);
            abort_unless(DB::table('support_messages')->where('ticket_id', $id)->where('id', $input['message_id'])->exists(), 422);
            $key = $request->user()->is_admin ? 'admin_read_id' : 'customer_read_id';
            DB::table('support_tickets')->where('id', $id)->update([$key => max($ticket->$key, $input['message_id'])]);
        });

        return response()->json(['message' => 'Read.']);
    }
}
