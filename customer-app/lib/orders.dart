import 'package:flutter/material.dart';
import 'customer_api.dart';
import 'ui.dart';
import 'support.dart';
import 'discovery.dart';

class CustomerOrderPage extends StatefulWidget {
  const CustomerOrderPage({
    super.key,
    required this.api,
    required this.reference,
    this.initial,
    this.reorder,
  });
  final CustomerApi api;
  final String reference;
  final CustomerOrder? initial;
  final Future<void> Function(String)? reorder;
  @override
  State<CustomerOrderPage> createState() => _CustomerOrderPageState();
}

class _CustomerOrderPageState extends State<CustomerOrderPage> {
  late CustomerOrder? order = widget.initial;
  bool busy = false;
  String? error;
  @override
  void initState() {
    super.initState();
    refresh();
  }

  Future<void> refresh() async {
    if (busy) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final fresh = await widget.api.order(widget.reference);
      if (mounted) setState(() => order = fresh);
    } catch (e) {
      if (mounted) {
        setState(() {
          error = friendlyError(e);
          if (e is ApiError && e.status == 401) order = null;
        });
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> request(String type) async {
    final sent = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => RequestPage(
          api: widget.api,
          reference: widget.reference,
          type: type,
        ),
      ),
    );
    if (sent == true && mounted) await refresh();
  }

  @override
  Widget build(BuildContext context) {
    final current = order;
    final stages = ['received', 'picking', 'out_for_delivery', 'delivered'];
    final stage = current == null ? -1 : stages.indexOf(current.status);
    final requested = current?.requests.map((e) => e['type']).toSet() ?? {};
    return Scaffold(
      appBar: AppBar(
        title: const Text('Track Order'),
        actions: [
          IconButton(
            onPressed: busy ? null : refresh,
            tooltip: 'Refresh order',
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: ShopBody(
        refresh: refresh,
        children: [
          if (busy) const LinearProgressIndicator(),
          if (error != null) ErrorBox(error!, retry: busy ? null : refresh),
          if (current != null) ...[
            OrderDiscovery(
              api: widget.api,
              order: current,
              reorder: widget.reorder,
            ),
            if (current.sample)
              const Chip(
                label: Text('Sample order • No real delivery or payment'),
              ),
            Text(
              current.status == 'cancelled'
                  ? 'Order cancelled'
                  : current.status == 'delivered'
                  ? 'Delivered with care.'
                  : current.json['vendorReviewRequired'] == true
                  ? 'Your order is awaiting admin review.'
                  : current.status == 'out_for_delivery'
                  ? 'Your order is on its way.'
                  : 'Your order is being prepared.',
              style: Theme.of(context).textTheme.headlineMedium,
            ),
            if (current.json['vendorRejectionReason'] is String)
              ShopCard(
                child: Text(
                  'Restaurant update: ${current.json['vendorRejectionReason']}',
                ),
              ),
            if (current.json['estimatedReadyAt'] is String)
              ShopCard(
                child: Text(
                  'Estimated preparation completion: ${DateTime.parse(current.json['estimatedReadyAt']).toLocal()}. Delivery follows pickup.',
                ),
              ),
            const SizedBox(height: 12),
            SelectableText(current.id, style: const TextStyle(color: muted)),
            const SizedBox(height: 10),
            if (stage >= 0)
              ShopCard(
                child: Column(
                  children: [
                    for (var i = 0; i < stages.length; i++)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: Icon(
                          i <= stage
                              ? Icons.check_circle
                              : Icons.radio_button_unchecked,
                          color: i <= stage ? blue : muted,
                        ),
                        title: Text(
                          [
                            'Order received',
                            'Preparing your order',
                            'Out for delivery',
                            'Delivered',
                          ][i],
                        ),
                        subtitle: i == stage
                            ? const Text('Current status')
                            : null,
                      ),
                  ],
                ),
              ),
            OutlinedButton.icon(
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => CustomerSupportPage(
                    api: widget.api,
                    orderReference: current.id,
                  ),
                ),
              ),
              icon: const Icon(Icons.support_agent),
              label: const Text('Get help with this order'),
            ),
            const Text(
              'Pull down to refresh delivery updates.',
              style: TextStyle(color: muted),
            ),
            const SizedBox(height: 12),
            Text('Items', style: Theme.of(context).textTheme.titleLarge),
            for (final item in current.items)
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(item['name']),
                subtitle: Text(
                  '${item['qty']} × ${item['quantity']}${(item['options'] as List? ?? []).isNotEmpty ? ' · ${(item['options'] as List).map((o) => o['name']).join(', ')}' : ''}',
                ),
                trailing: Text(
                  rupees(
                    ((item['price'] as num) * 100).round() *
                        (item['quantity'] as int),
                  ),
                ),
              ),
            if (current.json['deliveryPricing'] is Map)
              DeliveryBreakdown(
                Map<String, dynamic>.from(current.json['deliveryPricing']),
              ),
            BillView(Map<String, dynamic>.from(current.json['bill'])),
            ShopCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Delivery address',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const SizedBox(height: 8),
                  Text(current.address['name']),
                  Text(
                    '${current.address['street']}, ${current.address['city']} ${current.address['pin']}',
                  ),
                  const SizedBox(height: 12),
                  Text(current.json['delivery']),
                  Text(current.json['payment']),
                ],
              ),
            ),
            if (current.requests.isNotEmpty) ...[
              Text(
                'Your requests',
                style: Theme.of(context).textTheme.titleLarge,
              ),
              for (final item in current.requests)
                ShopCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '${item['type']} · ${item['status']}',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      Text(item['reason']),
                      if (item['reviewNote'] != null) Text(item['reviewNote']),
                    ],
                  ),
                ),
            ],
            if (!current.sample && error == null) ...[
              if (['received', 'picking'].contains(current.status) &&
                  !requested.contains('cancellation'))
                OutlinedButton(
                  onPressed: busy ? null : () => request('cancellation'),
                  child: const Text('Request cancellation'),
                ),
              if (current.status == 'delivered' &&
                  !requested.contains('return'))
                OutlinedButton(
                  onPressed: busy ? null : () => request('return'),
                  child: const Text('Request a return'),
                ),
              if (['delivered', 'cancelled'].contains(current.status) &&
                  current.json['paymentStatus'] == 'paid' &&
                  !requested.contains('refund') &&
                  (current.json['refunded'] as int) < current.total)
                OutlinedButton(
                  onPressed: busy ? null : () => request('refund'),
                  child: const Text('Request a refund'),
                ),
            ],
          ],
        ],
      ),
    );
  }
}

class RequestPage extends StatefulWidget {
  const RequestPage({
    super.key,
    required this.api,
    required this.reference,
    required this.type,
  });
  final CustomerApi api;
  final String reference, type;
  @override
  State<RequestPage> createState() => _RequestPageState();
}

class _RequestPageState extends State<RequestPage> {
  final reason = TextEditingController();
  final form = GlobalKey<FormState>();
  bool busy = false;
  String? error;
  @override
  void dispose() {
    reason.dispose();
    super.dispose();
  }

  Future<void> submit() async {
    if (!form.currentState!.validate()) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.api.aftercare(
        widget.reference,
        widget.type,
        reason.text.trim(),
      );
      if (mounted) Navigator.pop(context, true);
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
      appBar: AppBar(title: Text('Request ${widget.type}')),
      body: ShopBody(
        children: [
          const Text(
            'Tell us what happened. Our team will review your request and update its status here.',
          ),
          const SizedBox(height: 20),
          Form(
            key: form,
            child: TextFormField(
              controller: reason,
              enabled: !busy,
              minLines: 4,
              maxLines: 8,
              maxLength: 2000,
              decoration: const InputDecoration(labelText: 'Reason'),
              validator: (value) => (value?.trim().length ?? 0) < 5
                  ? 'Please provide at least 5 characters'
                  : null,
            ),
          ),
          if (error != null) ErrorBox(error!),
          const SizedBox(height: 20),
          FilledButton(
            onPressed: busy ? null : submit,
            child: Text(busy ? 'Submitting…' : 'Submit request'),
          ),
        ],
      ),
    ),
  );
}

class TransactionsPage extends StatefulWidget {
  const TransactionsPage({super.key, required this.api});
  final CustomerApi api;
  @override
  State<TransactionsPage> createState() => _TransactionsPageState();
}

class _TransactionsPageState extends State<TransactionsPage> {
  List<Map<String, dynamic>> rows = [];
  bool busy = false;
  String? error;
  @override
  void initState() {
    super.initState();
    refresh();
  }

  Future<void> refresh() async {
    if (busy) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final result = await widget.api.transactions();
      if (mounted) setState(() => rows = result);
    } catch (e) {
      if (mounted) {
        setState(() {
          error = friendlyError(e);
          rows = [];
        });
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Transactions')),
    body: ShopBody(
      refresh: refresh,
      children: [
        if (busy) const LinearProgressIndicator(),
        if (error != null) ErrorBox(error!, retry: busy ? null : refresh),
        if (!busy && error == null && rows.isEmpty)
          const ShopCard(
            child: Text('Your payments and refunds will appear here.'),
          ),
        for (final row in rows)
          ShopCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        '${row['type']} · ${row['status']}',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                    ),
                    Text(rupees(row['amount'])),
                  ],
                ),
                Text('Order ${row['orderId']}'),
                Text('Method: ${row['method']}'),
                if (row['reference'] != null)
                  SelectableText('Reference: ${row['reference']}'),
              ],
            ),
          ),
      ],
    ),
  );
}
