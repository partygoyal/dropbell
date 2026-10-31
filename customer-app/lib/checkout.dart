import 'package:flutter/material.dart';
import 'dart:async';
import 'customer_api.dart';
import 'ui.dart';
import 'orders.dart';

class BagPage extends StatelessWidget {
  const BagPage({
    super.key,
    required this.cart,
    required this.checkout,
    this.api,
    this.profile,
    this.manageAddresses,
    this.onAddressSelected,
  });
  final ShoppingCart cart;
  final Future<void> Function() checkout;
  final CustomerApi? api;
  final CustomerProfile? profile;
  final Future<CustomerProfile?> Function()? manageAddresses;
  final void Function(String)? onAddressSelected;
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('My Cart')),
    body: AnimatedBuilder(
      animation: cart,
      builder: (context, _) => ShopBody(
        children: [
          if (cart.lines.isEmpty)
            const ShopCard(
              child: Column(
                children: [
                  Icon(Icons.shopping_bag_outlined, size: 64, color: blue),
                  SizedBox(height: 16),
                  Text('Your bag is waiting for good things.'),
                ],
              ),
            ),
          for (final line in cart.lines.values)
            ShopCard(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  ProductImage(line.product),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          line.product.name,
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        Text(
                          line.product.pack,
                          style: const TextStyle(color: muted),
                        ),
                        Text(rupees(line.product.price)),
                        QuantityControl(
                          product: line.product,
                          quantity: line.quantity,
                          cart: cart,
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          if (cart.lines.isNotEmpty) ...[
            const SizedBox(height: 20),
            Text(
              'Estimated items total: ${rupees(cart.estimate)}',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 8),
            const Text(
              'Final prices, discounts and delivery charges are confirmed at checkout.',
            ),
            const SizedBox(height: 20),
            if (api != null && api!.signedIn && manageAddresses != null)
              BagDeliveryPreview(
                api: api!,
                cart: cart,
                initialProfile: profile,
                manageAddresses: manageAddresses!,
                onAddressSelected: onAddressSelected,
              ),
            if (api != null && !api!.signedIn) ...[
              const SizedBox(height: 8),
              const Text(
                'You can sign in or create an account at checkout. Your cart will stay saved.',
                style: TextStyle(color: muted),
              ),
              const SizedBox(height: 16),
            ],
            FilledButton(
              onPressed: cart.busy ? null : checkout,
              child: const Text('Review bag & checkout'),
            ),
          ],
          const SizedBox(height: 12),
          OutlinedButton(
            onPressed: checkout,
            child: const Text('Recover an unconfirmed checkout'),
          ),
        ],
      ),
    ),
  );
}

class QuantityControl extends StatelessWidget {
  const QuantityControl({
    super.key,
    required this.product,
    required this.quantity,
    required this.cart,
  });
  final Product product;
  final int quantity;
  final ShoppingCart cart;
  Future<void> change(BuildContext context, int value) async {
    try {
      await cart.setQuantity(product, value);
    } catch (e) {
      if (context.mounted) notifyError(context, e);
    }
  }

  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      IconButton(
        onPressed: cart.busy || quantity == 0
            ? null
            : () => change(context, quantity - 1),
        tooltip: 'Remove one ${product.name}',
        icon: const Icon(Icons.remove_circle_outline),
      ),
      Text('$quantity', style: const TextStyle(fontWeight: FontWeight.bold)),
      IconButton(
        onPressed:
            cart.busy || quantity >= product.maxQuantity || !product.available
            ? null
            : () => change(context, quantity + 1),
        tooltip: 'Add one ${product.name}',
        icon: const Icon(Icons.add_circle_outline),
      ),
    ],
  );
}

class CheckoutPage extends StatefulWidget {
  const CheckoutPage({
    super.key,
    required this.api,
    required this.profile,
    required this.cart,
    required this.manageAddresses,
    this.initialAddressId,
  });
  final CustomerApi api;
  final CustomerProfile profile;
  final ShoppingCart cart;
  final Future<CustomerProfile?> Function() manageAddresses;
  final String? initialAddressId;
  @override
  State<CheckoutPage> createState() => _CheckoutPageState();
}

class _CheckoutPageState extends State<CheckoutPage> {
  late final attempt = CheckoutAttempt(widget.api, widget.profile.phone);
  late CustomerProfile profile = widget.profile;
  late final lines = widget.cart.lines.values.toList();
  SavedAddress? address;
  Map<String, dynamic>? quote;
  final coupon = TextEditingController();
  String delivery = 'standard';
  String? error;
  bool busy = false, restored = false;
  @override
  void initState() {
    super.initState();
    initialize();
  }

  @override
  void dispose() {
    coupon.dispose();
    super.dispose();
  }

  Future<void> initialize() async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await attempt.restore();
      if (profile.addresses.isNotEmpty) {
        address =
            profile.addresses
                .where((a) => a.id == widget.initialAddressId)
                .firstOrNull ??
            profile.addresses.first;
      }
      restored = true;
    } catch (e) {
      error = friendlyError(e);
    }
    if (!mounted) return;
    setState(() => busy = false);
    if (restored &&
        attempt.pending == null &&
        address != null &&
        lines.isNotEmpty) {
      await review();
    }
  }

  Future<void> review() async {
    if (busy || address == null || lines.isEmpty || attempt.pending != null) {
      return;
    }
    setState(() {
      busy = true;
      quote = null;
      error = null;
    });
    try {
      final result = await widget.api.quote(
        lines.map((e) => e.item).toList(),
        address!.pin,
        coupon.text.trim().toUpperCase(),
        address!.payload,
      );
      if (mounted) setState(() => quote = result);
    } catch (e) {
      if (mounted) setState(() => error = friendlyError(e));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> manage() async {
    final updated = await widget.manageAddresses();
    if (!mounted || updated == null) return;
    setState(() {
      profile = updated;
      address = updated.addresses.isEmpty ? null : updated.addresses.first;
      quote = null;
    });
    await review();
  }

  Future<void> place() async {
    if (busy || !restored) return;
    final pending = attempt.pending;
    if (pending == null && (quote == null || address == null)) return;
    final total =
        (pending?['expected_total'] ?? quote!['bill']['total']) as int;
    final destination = pending == null
        ? address!.text
        : '${pending['address']['street']}, ${pending['address']['city']} ${pending['address']['pin']}';
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(
          pending == null ? 'Place this order?' : 'Confirm previous checkout?',
        ),
        content: Text(
          '${rupees(total)} · Pay on delivery\n\n$destination${pending == null ? '' : '\n\nWe’ll check the same request so it cannot create a duplicate order.'}',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Go back'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Confirm order'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    setState(() {
      busy = true;
      error = null;
    });
    CustomerOrder? placed;
    try {
      placed = await attempt.submit(
        pending ??
            {
              'items': lines.map((e) => e.item).toList(),
              'address': address!.payload,
              'coupon': coupon.text.trim().isEmpty
                  ? null
                  : coupon.text.trim().toUpperCase(),
              'delivery': delivery,
              'payment': 'cod',
              'expected_total': total,
            },
      );
    } catch (e) {
      if (mounted) {
        setState(() {
          error = friendlyError(e);
          if (attempt.pending == null) quote = null;
        });
      }
    }
    if (placed != null) {
      try {
        await widget.cart.consume(
          attempt.pending!['request_key'],
          attempt.pending!['items'],
        );
        await attempt.finish();
      } catch (_) {
        if (mounted) {
          notifyError(
            context,
            const ApiError(
              'Order placed. We could not finish saving the result. Use Recover checkout to reconcile it safely.',
            ),
          );
        }
      }
      if (mounted) {
        Navigator.of(context).pushReplacement(
          MaterialPageRoute<void>(
            builder: (_) => CustomerOrderPage(
              api: widget.api,
              reference: placed!.id,
              initial: placed,
            ),
          ),
        );
      }
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final pending = attempt.pending;
    final quotedItems = (quote?['items'] as List?) ?? [];
    return PopScope(
      canPop: !busy,
      child: Scaffold(
        appBar: AppBar(title: const Text('Checkout')),
        body: ShopBody(
          children: [
            if (busy) const LinearProgressIndicator(),
            if (error != null)
              ErrorBox(
                error!,
                retry: busy
                    ? null
                    : !restored
                    ? initialize
                    : null,
              ),
            if (pending != null) ...[
              Text(
                'Finish your previous checkout',
                style: Theme.of(context).textTheme.headlineMedium,
              ),
              const SizedBox(height: 12),
              const Text(
                'We have not received a confirmed result for this request. Retry it safely before starting another checkout.',
              ),
              ShopCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('${(pending['items'] as List).length} products'),
                    Text('${pending['address']['name']}'),
                    Text(
                      '${pending['address']['street']}, ${pending['address']['city']} ${pending['address']['pin']}',
                    ),
                    const SizedBox(height: 12),
                    Text(
                      'Pay on delivery: ${rupees(pending['expected_total'])}',
                    ),
                  ],
                ),
              ),
              FilledButton(
                onPressed: busy ? null : place,
                child: const Text('Retry checkout'),
              ),
            ] else if (restored) ...[
              if (lines.isEmpty)
                const ShopCard(
                  child: Text(
                    'Your bag is empty and there is no unconfirmed checkout for this account.',
                  ),
                ),
              if (lines.isNotEmpty) ...[
                Text(
                  'Delivery address',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                const SizedBox(height: 12),
                if (profile.addresses.isNotEmpty)
                  DropdownButtonFormField<String>(
                    key: ValueKey(address?.id),
                    initialValue: address?.id,
                    isExpanded: true,
                    decoration: const InputDecoration(labelText: 'Deliver to'),
                    items: [
                      for (final saved in profile.addresses)
                        DropdownMenuItem(
                          value: saved.id,
                          child: Text('${saved.label} · ${saved.pin}'),
                        ),
                    ],
                    onChanged: busy
                        ? null
                        : (value) async {
                            setState(() {
                              address = profile.addresses.firstWhere(
                                (a) => a.id == value,
                              );
                              quote = null;
                            });
                            await review();
                          },
                  ),
                if (address != null)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    child: Text('${address!.json['name']}\n${address!.text}'),
                  ),
                TextButton(
                  onPressed: busy ? null : manage,
                  child: const Text('Add or change saved addresses'),
                ),
                const SizedBox(height: 16),
                Text(
                  'Your items',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                for (final line in lines)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text(line.product.name),
                    subtitle: Text('${line.product.pack} × ${line.quantity}'),
                    trailing: Text(
                      quote == null
                          ? 'To confirm'
                          : rupees(
                              ((quotedItems.firstWhere(
                                            (e) => e['id'] == line.product.id,
                                          )
                                          as Map)['unit_price_paise']
                                      as int) *
                                  line.quantity,
                            ),
                    ),
                  ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  initialValue: delivery,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Delivery preference',
                  ),
                  items: const [
                    DropdownMenuItem(
                      value: 'standard',
                      child: Text('Next available delivery'),
                    ),
                    DropdownMenuItem(
                      value: 'evening',
                      child: Text('Evening delivery'),
                    ),
                  ],
                  onChanged: busy
                      ? null
                      : (value) => setState(() => delivery = value!),
                ),
                const SizedBox(height: 18),
                TextField(
                  controller: coupon,
                  enabled: !busy,
                  maxLength: 30,
                  textCapitalization: TextCapitalization.characters,
                  decoration: const InputDecoration(
                    labelText: 'Coupon code (optional)',
                  ),
                  onChanged: (_) => setState(() => quote = null),
                ),
                OutlinedButton(
                  onPressed: busy || address == null ? null : review,
                  child: const Text('Review final total'),
                ),
                if (quote != null) ...[
                  DeliveryBreakdown(Map<String, dynamic>.from(quote!['rules'])),
                  BillView(Map<String, dynamic>.from(quote!['bill'])),
                  const ShopCard(
                    child: ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(Icons.radio_button_checked, color: blue),
                      title: Text('Cash on Delivery'),
                      subtitle: Text('Pay when you receive your order.'),
                    ),
                  ),
                  const Text(
                    'Delivery timing is a preference, not a guaranteed slot.',
                    style: TextStyle(color: muted, fontSize: 12),
                  ),
                  const SizedBox(height: 18),
                  FilledButton(
                    onPressed: busy ? null : place,
                    child: Text(
                      'Place order · ${rupees(quote!['bill']['total'])}',
                    ),
                  ),
                ],
              ],
            ],
          ],
        ),
      ),
    );
  }
}

class BagDeliveryPreview extends StatefulWidget {
  const BagDeliveryPreview({
    super.key,
    required this.api,
    required this.cart,
    required this.initialProfile,
    required this.manageAddresses,
    this.onAddressSelected,
  });
  final CustomerApi api;
  final ShoppingCart cart;
  final CustomerProfile? initialProfile;
  final Future<CustomerProfile?> Function() manageAddresses;
  final void Function(String)? onAddressSelected;
  @override
  State<BagDeliveryPreview> createState() => _BagDeliveryPreviewState();
}

class _BagDeliveryPreviewState extends State<BagDeliveryPreview> {
  late CustomerProfile? profile = widget.initialProfile;
  SavedAddress? address;
  Map<String, dynamic>? quote;
  Timer? timer;
  int generation = 0;
  bool busy = false;
  String? error;
  @override
  void initState() {
    super.initState();
    widget.cart.addListener(cartChanged);
    if (profile?.addresses.isNotEmpty == true) {
      address = profile!.addresses.first;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) {
          widget.onAddressSelected?.call(address!.id);
          review();
        }
      });
    }
  }

  @override
  void dispose() {
    timer?.cancel();
    widget.cart.removeListener(cartChanged);
    super.dispose();
  }

  void cartChanged() {
    timer?.cancel();
    generation++;
    if (mounted) {
      setState(() {
        quote = null;
        busy = false;
      });
    }
    if (!widget.cart.busy && address != null && widget.cart.lines.isNotEmpty) {
      timer = Timer(const Duration(milliseconds: 400), review);
    }
  }

  Future<void> choose() async {
    final updated = await widget.manageAddresses();
    if (!mounted || updated == null) return;
    setState(() {
      profile = updated;
      address = updated.addresses.firstOrNull;
      quote = null;
    });
    if (address != null) widget.onAddressSelected?.call(address!.id);
    await review();
  }

  Future<void> review() async {
    if (address == null || widget.cart.lines.isEmpty) return;
    final current = ++generation;
    setState(() {
      busy = true;
      error = null;
      quote = null;
    });
    try {
      final result = await widget.api.quote(
        widget.cart.lines.values.map((line) => line.item).toList(),
        address!.pin,
        '',
        address!.payload,
      );
      if (mounted && current == generation) setState(() => quote = result);
    } catch (e) {
      if (mounted && current == generation) {
        setState(() => error = friendlyError(e));
      }
    } finally {
      if (mounted && current == generation) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      const SizedBox(height: 12),
      Text(
        'Delivery & bill summary',
        style: Theme.of(context).textTheme.titleLarge,
      ),
      if (profile?.addresses.isNotEmpty == true) ...[
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          key: ValueKey(address?.id),
          initialValue: address?.id,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Delivery location'),
          items: [
            for (final a in profile!.addresses)
              DropdownMenuItem(
                value: a.id,
                child: Text('${a.label} · ${a.pin}'),
              ),
          ],
          onChanged: busy
              ? null
              : (id) {
                  setState(
                    () => address = profile!.addresses.firstWhere(
                      (a) => a.id == id,
                    ),
                  );
                  widget.onAddressSelected?.call(id!);
                  review();
                },
        ),
        if (address != null)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Text(address!.text),
          ),
      ],
      TextButton(
        onPressed: busy ? null : choose,
        child: Text(
          address == null
              ? 'Choose delivery address to calculate charges'
              : 'Manage delivery addresses',
        ),
      ),
      if (busy) const LinearProgressIndicator(),
      if (error != null) ErrorBox(error!, retry: busy ? null : review),
      if (quote != null) ...[
        DeliveryBreakdown(Map<String, dynamic>.from(quote!['rules'])),
        BillView(Map<String, dynamic>.from(quote!['bill'])),
      ],
    ],
  );
}
