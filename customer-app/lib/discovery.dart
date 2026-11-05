import 'dart:async';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import 'customer_api.dart';
import 'ui.dart';

Future<Product?> chooseDishOptions(
  BuildContext context,
  Product product,
  CartLine? existing,
) async {
  final groups = product.optionGroups;
  final selected = <String>{...existing?.product.optionIds ?? []};
  String? error;
  return showDialog<Product>(
    context: context,
    builder: (context) => StatefulBuilder(
      builder: (context, update) {
        final choices = groups
            .expand((group) => group['choices'] as List)
            .where((choice) => selected.contains(choice['id']))
            .toList();
        final extra = choices.fold<int>(
          0,
          (sum, choice) => sum + (choice['price_paise'] as int),
        );
        return AlertDialog(
          title: const Text('Make it yours'),
          content: SizedBox(
            width: 440,
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${product.name}\nOne configuration per product in your bag.',
                  ),
                  for (final group in groups) ...[
                    Padding(
                      padding: const EdgeInsets.only(top: 14),
                      child: Text(
                        '${group['name']} · ${group['min'] > 0 ? 'Required' : 'Optional'}',
                        style: const TextStyle(fontWeight: FontWeight.bold),
                      ),
                    ),
                    for (final choice in group['choices'])
                      CheckboxListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(
                          '${choice['name']} · +${rupees(choice['price_paise'])}',
                        ),
                        value: selected.contains(choice['id']),
                        onChanged: (value) => update(() {
                          if (value == true) {
                            if (group['max'] == 1) {
                              selected.removeAll(
                                (group['choices'] as List).map(
                                  (c) => c['id'] as String,
                                ),
                              );
                            }
                            selected.add(choice['id']);
                          } else {
                            selected.remove(choice['id']);
                          }
                        }),
                      ),
                  ],
                  Text('Per portion: ${rupees(product.price + extra)}'),
                  if (error != null)
                    Text(error!, style: const TextStyle(color: Colors.red)),
                ],
              ),
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () {
                for (final group in groups) {
                  final count = (group['choices'] as List)
                      .where((c) => selected.contains(c['id']))
                      .length;
                  if (count < group['min'] || count > group['max']) {
                    update(
                      () => error =
                          'Choose ${group['min']}–${group['max']} options for ${group['name']}.',
                    );
                    return;
                  }
                }
                Navigator.pop(
                  context,
                  Product({
                    ...product.json,
                    'price_paise': product.price + extra,
                    'selected_vendor_id':
                        product.vendorId ?? product.json['option_vendor_id'],
                    'selected_option_ids': selected.toList(),
                    'selected_option_names': choices
                        .map((c) => c['name'])
                        .toList(),
                    'menu_revision': product.json['menu_revision'] ?? 0,
                  }),
                );
              },
              child: const Text('Use these options'),
            ),
          ],
        );
      },
    ),
  );
}

class FavouriteButton extends StatefulWidget {
  const FavouriteButton({
    super.key,
    required this.api,
    required this.id,
    this.kind = 'product',
  });
  final CustomerApi api;
  final int id;
  final String kind;
  @override
  State<FavouriteButton> createState() => _FavouriteButtonState();
}

class _FavouriteButtonState extends State<FavouriteButton> {
  bool saved = false, busy = false;
  @override
  void initState() {
    super.initState();
    if (widget.api.signedIn) load();
  }

  Future<void> load() async {
    try {
      final rows =
          (await widget.api.request('GET', 'customer/favourites'))['data']
              as List;
      if (mounted) {
        setState(
          () => saved = rows.any(
            (r) => r['kind'] == widget.kind && r['target_id'] == widget.id,
          ),
        );
      }
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) => TextButton.icon(
    onPressed: busy
        ? null
        : () async {
            if (!widget.api.signedIn) {
              notifyError(
                context,
                const ApiError('Sign in to save favourites.'),
              );
              return;
            }
            setState(() => busy = true);
            try {
              await widget.api.request('PUT', 'customer/favourites', {
                'kind': widget.kind,
                'target_id': widget.id,
                'saved': !saved,
              });
              if (mounted) setState(() => saved = !saved);
            } catch (e) {
              if (context.mounted) notifyError(context, e);
            } finally {
              if (mounted) setState(() => busy = false);
            }
          },
    icon: Icon(saved ? Icons.favorite : Icons.favorite_border),
    label: Text(saved ? 'Saved' : 'Save'),
  );
}

class FavouritesPage extends StatefulWidget {
  const FavouritesPage({super.key, required this.api, required this.open});
  final CustomerApi api;
  final Future<void> Function(Map<String, dynamic>) open;
  @override
  State<FavouritesPage> createState() => _FavouritesPageState();
}

class _FavouritesPageState extends State<FavouritesPage> {
  List<dynamic> rows = [];
  String? error;
  bool loading = true;
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    try {
      final body = await widget.api.request('GET', 'customer/favourites');
      if (mounted) setState(() => rows = body['data']);
    } catch (e) {
      if (mounted) setState(() => error = friendlyError(e));
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('My favourites')),
    body: ShopBody(
      refresh: load,
      children: [
        if (loading) const LinearProgressIndicator(),
        if (error != null) ErrorBox(error!, retry: load),
        if (!loading && rows.isEmpty)
          const Text('Save products and restaurants to find them here.'),
        for (final row in rows)
          ShopCard(
            child: ListTile(
              title: Text(row['name']),
              subtitle: Text(
                row['available']
                    ? 'View ${row['kind']}'
                    : 'Currently unavailable',
              ),
              onTap: () async {
                try {
                  await widget.open(Map<String, dynamic>.from(row));
                } catch (e) {
                  if (context.mounted) notifyError(context, e);
                }
              },
              trailing: IconButton(
                tooltip: 'Remove favourite',
                icon: const Icon(Icons.close),
                onPressed: () async {
                  try {
                    await widget.api.request('PUT', 'customer/favourites', {
                      'kind': row['kind'],
                      'target_id': row['target_id'],
                      'saved': false,
                    });
                    await load();
                  } catch (e) {
                    if (context.mounted) notifyError(context, e);
                  }
                },
              ),
            ),
          ),
      ],
    ),
  );
}

class ProductReviews extends StatefulWidget {
  const ProductReviews({super.key, required this.api, required this.id});
  final CustomerApi api;
  final int id;
  @override
  State<ProductReviews> createState() => _ProductReviewsState();
}

class _ProductReviewsState extends State<ProductReviews> {
  List<dynamic> rows = [];
  String? error;
  int page = 0, last = 1;
  bool busy = false;
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    if (busy) return;
    setState(() => busy = true);
    try {
      final b = await widget.api.request(
        'GET',
        'products/${widget.id}/reviews?page=${page + 1}',
      );
      if (mounted) {
        setState(() {
          rows.addAll(b['data']);
          page = b['current_page'];
          last = b['last_page'];
          error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => error = friendlyError(e));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      const SectionTitle('Verified-purchase reviews'),
      if (error != null) ErrorBox(error!, retry: load),
      if (!busy && error == null && rows.isEmpty)
        const Text('No published reviews yet.'),
      for (final row in rows)
        ShopCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '${row['rating']}/5 · ${row['reviewer']} · Verified purchase',
              ),
              Text(row['comment']),
            ],
          ),
        ),
      if (busy) const LinearProgressIndicator(),
      if (page < last && !busy)
        TextButton(onPressed: load, child: const Text('More reviews')),
    ],
  );
}

class OrderDiscovery extends StatefulWidget {
  const OrderDiscovery({
    super.key,
    required this.api,
    required this.order,
    this.reorder,
  });
  final CustomerApi api;
  final CustomerOrder order;
  final Future<void> Function(String)? reorder;
  @override
  State<OrderDiscovery> createState() => _OrderDiscoveryState();
}

class _OrderDiscoveryState extends State<OrderDiscovery>
    with WidgetsBindingObserver {
  Map<String, dynamic>? tracking;
  Timer? timer;
  bool busy = false, foreground = true;
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    refresh();
    timer = Timer.periodic(const Duration(seconds: 15), (_) => refresh());
  }

  @override
  void dispose() {
    timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (foreground) refresh();
  }

  Future<void> refresh() async {
    if (busy ||
        !foreground ||
        ![
          'received',
          'picking',
          'out_for_delivery',
        ].contains(widget.order.status) ||
        widget.order.sample) {
      return;
    }
    busy = true;
    try {
      final b = await widget.api.request(
        'GET',
        'customer/orders/${widget.order.id}/tracking',
      );
      if (mounted) {
        setState(() => tracking = Map<String, dynamic>.from(b['data']));
      }
    } catch (_) {
      if (mounted) setState(() => tracking = null);
    } finally {
      busy = false;
    }
  }

  Future<void> review() async {
    final items = widget.order.items.where((i) => i['id'] != null).toList();
    if (items.isEmpty) return;
    int product = items.first['id'], rating = 5;
    final comment = TextEditingController();
    final send = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, update) => AlertDialog(
          title: const Text('Review your purchase'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                DropdownButtonFormField<int>(
                  initialValue: product,
                  items: items
                      .map(
                        (i) => DropdownMenuItem<int>(
                          value: i['id'],
                          child: Text(
                            i['name'],
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      )
                      .toList(),
                  onChanged: (value) => update(() => product = value!),
                ),
                DropdownButtonFormField<int>(
                  initialValue: rating,
                  items: [5, 4, 3, 2, 1]
                      .map(
                        (r) =>
                            DropdownMenuItem(value: r, child: Text('$r stars')),
                      )
                      .toList(),
                  onChanged: (v) => update(() => rating = v!),
                ),
                TextField(
                  controller: comment,
                  maxLength: 1000,
                  minLines: 3,
                  maxLines: 5,
                  decoration: const InputDecoration(
                    labelText: 'Your experience',
                  ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Submit for moderation'),
            ),
          ],
        ),
      ),
    );
    if (send == true) {
      try {
        final b = await widget.api.request(
          'POST',
          'customer/orders/${widget.order.id}/reviews',
          {'product_id': product, 'rating': rating, 'comment': comment.text},
        );
        if (mounted) {
          ScaffoldMessenger.of(
            context,
          ).showSnackBar(SnackBar(content: Text(b['message'])));
        }
      } catch (e) {
        if (mounted) notifyError(context, e);
      }
    }
    comment.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final estimate =
        tracking?['estimate'] ?? widget.order.json['deliveryEstimate'];
    final location = tracking?['location'];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (estimate is Map)
          ShopCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  estimate['minutes'] == null
                      ? 'Delivery estimate needs updating'
                      : 'Approximately ${estimate['minutes']} minutes remaining',
                  style: const TextStyle(fontWeight: FontWeight.bold),
                ),
                Text(estimate['note']),
              ],
            ),
          ),
        if (widget.order.status == 'out_for_delivery')
          ShopCard(
            child: Column(
              children: [
                Text(
                  tracking?['message'] ??
                      'Live location is not currently available.',
                ),
                if (location is Map && tracking?['sharing'] == true)
                  TextButton.icon(
                    icon: const Icon(Icons.map_outlined),
                    label: const Text('View delivery partner on map'),
                    onPressed: () async {
                      try {
                        await launchUrl(
                          Uri.parse(
                            'https://www.openstreetmap.org/?mlat=${location['latitude']}&mlon=${location['longitude']}#map=16/${location['latitude']}/${location['longitude']}',
                          ),
                          mode: LaunchMode.externalApplication,
                        );
                      } catch (e) {
                        if (context.mounted) notifyError(context, e);
                      }
                    },
                  ),
              ],
            ),
          ),
        if (!widget.order.sample && widget.reorder != null)
          OutlinedButton(
            onPressed: () => widget.reorder!(widget.order.id),
            child: const Text('Order again'),
          ),
        if (widget.order.status == 'delivered' && !widget.order.sample)
          TextButton(
            onPressed: review,
            child: const Text('Review this purchase'),
          ),
      ],
    );
  }
}
