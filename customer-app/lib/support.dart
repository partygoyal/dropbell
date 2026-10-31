import 'dart:async';
import 'package:flutter/material.dart';
import 'package:uuid/uuid.dart';
import 'customer_api.dart';
import 'ui.dart';

class CustomerSupportPage extends StatefulWidget {
  const CustomerSupportPage({
    super.key,
    required this.api,
    this.orderReference,
  });
  final CustomerApi api;
  final String? orderReference;
  @override
  State<CustomerSupportPage> createState() => _CustomerSupportPageState();
}

class _CustomerSupportPageState extends State<CustomerSupportPage> {
  List<dynamic> tickets = [];
  int? nextPage;
  bool busy = false;
  String? error;
  Timer? timer;
  @override
  void initState() {
    super.initState();
    load();
    timer = Timer.periodic(const Duration(seconds: 15), (_) {
      if (mounted && ModalRoute.of(context)?.isCurrent == true) {
        load(silent: true);
      }
    });
  }

  @override
  void dispose() {
    timer?.cancel();
    super.dispose();
  }

  Future<void> load({bool silent = false, int page = 1}) async {
    if (busy) return;
    busy = true;
    if (!silent) setState(() {});
    try {
      final result = await widget.api.request(
        'GET',
        'customer/support/tickets?page=$page',
      );
      if (mounted) {
        setState(() {
          tickets = page == 1
              ? result['data']
              : [...tickets, ...result['data']];
          nextPage = result['next_page'];
          error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => error = friendlyError(e));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> create() async {
    final id = await Navigator.of(context).push<int>(
      MaterialPageRoute(
        builder: (_) => NewConcernPage(
          api: widget.api,
          orderReference: widget.orderReference,
        ),
      ),
    );
    if (!mounted || id == null) return;
    await open(id);
  }

  Future<void> open(int id) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => SupportConversationPage(api: widget.api, id: id),
      ),
    );
    if (mounted) await load();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Customer Support')),
    body: ShopBody(
      refresh: load,
      children: [
        const SectionTitle('How can we help?'),
        const Text(
          'Raise a concern and chat with our support team. Replies appear here. Support may take time to respond.',
        ),
        const SizedBox(height: 16),
        FilledButton.icon(
          onPressed: create,
          icon: const Icon(Icons.add_comment_outlined),
          label: const Text('Raise a concern'),
        ),
        if (busy) const LinearProgressIndicator(),
        if (error != null) ErrorBox(error!, retry: load),
        if (tickets.isEmpty && !busy && error == null)
          const ShopCard(
            child: Text(
              'No concerns yet. Your conversations will appear here.',
            ),
          ),
        for (final ticket in tickets)
          ShopCard(
            child: ListTile(
              contentPadding: EdgeInsets.zero,
              onTap: () => open(ticket['id']),
              title: Text(ticket['subject']),
              subtitle: Text(
                '#${ticket['id']} · ${ticket['status'].toString().replaceAll('_', ' ')}',
              ),
              trailing: ticket['unread'] > 0
                  ? Badge(
                      label: Text('${ticket['unread']}'),
                      child: const Icon(Icons.chat_bubble_outline, color: blue),
                    )
                  : const Icon(Icons.chevron_right),
            ),
          ),
        if (nextPage != null)
          TextButton(
            onPressed: busy ? null : () => load(page: nextPage!),
            child: const Text('More concerns'),
          ),
      ],
    ),
  );
}

class NewConcernPage extends StatefulWidget {
  const NewConcernPage({super.key, required this.api, this.orderReference});
  final CustomerApi api;
  final String? orderReference;
  @override
  State<NewConcernPage> createState() => _NewConcernPageState();
}

class _NewConcernPageState extends State<NewConcernPage> {
  final subject = TextEditingController(), body = TextEditingController();
  final form = GlobalKey<FormState>();
  final requestKey = const Uuid().v4();
  bool busy = false;
  String? error;
  @override
  void dispose() {
    subject.dispose();
    body.dispose();
    super.dispose();
  }

  Future<void> submit() async {
    if (!form.currentState!.validate() || busy) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.api
          .request('POST', 'customer/support/tickets', {
            'request_key': requestKey,
            'subject': subject.text.trim(),
            'body': body.text.trim(),
            'order_reference': widget.orderReference,
          });
      if (mounted) Navigator.pop(context, result['data']['id'] as int);
    } catch (e) {
      if (mounted) setState(() => error = friendlyError(e));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: !busy,
    child: Scaffold(
      appBar: AppBar(title: const Text('Raise a concern')),
      body: ShopBody(
        children: [
          if (widget.orderReference != null)
            Text(
              'Order ${widget.orderReference}',
              style: const TextStyle(color: muted),
            ),
          const SizedBox(height: 12),
          Form(
            key: form,
            child: Column(
              children: [
                TextFormField(
                  controller: subject,
                  enabled: !busy,
                  maxLength: 120,
                  decoration: const InputDecoration(labelText: 'Subject'),
                  validator: (v) => (v?.trim().length ?? 0) < 3
                      ? 'Use at least 3 characters'
                      : null,
                ),
                const SizedBox(height: 14),
                TextFormField(
                  controller: body,
                  enabled: !busy,
                  maxLength: 2000,
                  minLines: 5,
                  maxLines: 8,
                  decoration: const InputDecoration(
                    labelText: 'Tell us what happened',
                  ),
                  validator: (v) => (v?.trim().length ?? 0) < 5
                      ? 'Please describe the concern'
                      : null,
                ),
              ],
            ),
          ),
          if (error != null) ErrorBox(error!),
          FilledButton(
            onPressed: busy ? null : submit,
            child: Text(busy ? 'Sending…' : 'Submit concern'),
          ),
        ],
      ),
    ),
  );
}

class SupportConversationPage extends StatefulWidget {
  const SupportConversationPage({
    super.key,
    required this.api,
    required this.id,
  });
  final CustomerApi api;
  final int id;
  @override
  State<SupportConversationPage> createState() =>
      _SupportConversationPageState();
}

class _SupportConversationPageState extends State<SupportConversationPage> {
  final reply = TextEditingController();
  Map<String, dynamic>? ticket;
  final messages = <int, Map<String, dynamic>>{};
  int? before;
  bool loading = false, sending = false;
  String? error;
  String requestKey = const Uuid().v4();
  Timer? timer;
  @override
  void initState() {
    super.initState();
    load();
    timer = Timer.periodic(const Duration(seconds: 10), (_) {
      if (mounted && ModalRoute.of(context)?.isCurrent == true) {
        load(silent: true);
      }
    });
  }

  @override
  void dispose() {
    timer?.cancel();
    reply.dispose();
    super.dispose();
  }

  Future<void> load({bool silent = false, bool older = false}) async {
    if (loading) return;
    loading = true;
    if (!silent) setState(() {});
    try {
      final result = await widget.api.request(
        'GET',
        'customer/support/tickets/${widget.id}${older ? '?before=$before' : ''}',
      );
      if (!mounted) return;
      final data = result['data'];
      for (final m in data['messages']) {
        messages[m['id']] = Map<String, dynamic>.from(m);
      }
      setState(() {
        ticket = Map<String, dynamic>.from(data['ticket']);
        if (older || before == null) before = data['next_before'];
        error = null;
      });
      if (data['messages'].isNotEmpty) {
        await widget.api.request(
          'POST',
          'customer/support/tickets/${widget.id}/read',
          {'message_id': data['messages'].last['id']},
        );
      }
    } catch (e) {
      if (mounted) setState(() => error = friendlyError(e));
    } finally {
      loading = false;
      if (mounted) setState(() {});
    }
  }

  Future<void> send() async {
    final text = reply.text.trim();
    if (text.isEmpty || sending) return;
    setState(() => sending = true);
    try {
      await widget.api.request(
        'POST',
        'customer/support/tickets/${widget.id}/messages',
        {'request_key': requestKey, 'body': text},
      );
      if (!mounted) return;
      reply.clear();
      requestKey = const Uuid().v4();
      await load();
    } catch (e) {
      if (mounted) setState(() => error = friendlyError(e));
    } finally {
      if (mounted) setState(() => sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rows = messages.values.toList()
      ..sort((a, b) => (a['id'] as int).compareTo(b['id'] as int));
    return Scaffold(
      appBar: AppBar(title: Text('Concern #${widget.id}')),
      body: Column(
        children: [
          Expanded(
            child: ShopBody(
              refresh: load,
              children: [
                if (ticket != null) ...[
                  SectionTitle(ticket!['subject']),
                  Text(
                    'Status: ${ticket!['status'].toString().replaceAll('_', ' ')}',
                  ),
                  if (ticket!['status'] == 'resolved')
                    const Text(
                      'This concern is resolved. Reply below if you still need help.',
                    ),
                ],
                if (error != null) ErrorBox(error!, retry: load),
                if (loading) const LinearProgressIndicator(),
                if (before != null)
                  TextButton(
                    onPressed: loading ? null : () => load(older: true),
                    child: const Text('Older messages'),
                  ),
                for (final message in rows)
                  Align(
                    alignment: message['sender_role'] == 'customer'
                        ? Alignment.centerRight
                        : Alignment.centerLeft,
                    child: Container(
                      margin: const EdgeInsets.only(top: 12),
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: message['sender_role'] == 'customer'
                            ? const Color(0xFFE8F3FF)
                            : Colors.white,
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            message['sender_role'] == 'customer'
                                ? 'You'
                                : 'Dropbell Support',
                            style: const TextStyle(
                              color: blue,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          const SizedBox(height: 6),
                          SelectableText(message['body']),
                          const SizedBox(height: 6),
                          Text(
                            message['created_at'],
                            style: const TextStyle(color: muted, fontSize: 10),
                          ),
                        ],
                      ),
                    ),
                  ),
              ],
            ),
          ),
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: TextField(
                      controller: reply,
                      enabled: !sending,
                      minLines: 1,
                      maxLines: 4,
                      maxLength: 2000,
                      decoration: const InputDecoration(
                        hintText: 'Write a reply',
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  IconButton(
                    onPressed: sending ? null : send,
                    tooltip: 'Send reply',
                    icon: const Icon(Icons.send, color: blue),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
