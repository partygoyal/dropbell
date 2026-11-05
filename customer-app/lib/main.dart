import 'promotions.dart';
import 'discovery.dart';
import 'package:url_launcher/url_launcher.dart';
import 'dart:async';
import 'app_recovery.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'account.dart';
import 'checkout.dart';
import 'customer_api.dart';
import 'orders.dart';
import 'ui.dart';
import 'delivery_pin.dart';
import 'support.dart';
import 'package:dropbell_mobile_notifications/notifications.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(RecoverableApp(builder: () => const CustomerApp()));
}

class CustomerApp extends StatelessWidget {
  const CustomerApp({super.key, this.api});
  final CustomerApi? api;
  @override
  Widget build(BuildContext context) => MaterialApp(
    title: 'Dropbell',
    debugShowCheckedModeBanner: false,
    scrollBehavior: const CustomerScrollBehavior().copyWith(overscroll: false),
    theme: ThemeData(
      fontFamily: 'Roboto',
      useMaterial3: true,
      colorScheme: ColorScheme.fromSeed(seedColor: blue, primary: blue),
      scaffoldBackgroundColor: const Color(0xFFF7FAFC),
      appBarTheme: const AppBarTheme(
        backgroundColor: blue,
        foregroundColor: Colors.white,
        elevation: 0,
        centerTitle: false,
        scrolledUnderElevation: 0,
        titleTextStyle: TextStyle(
          fontFamily: 'Roboto',
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: Colors.white,
        ),
        systemOverlayStyle: SystemUiOverlayStyle.light,
      ),
      textTheme: const TextTheme(
        headlineLarge: TextStyle(
          fontFamily: 'Roboto',
          color: navy,
          fontSize: 28,
          fontWeight: FontWeight.w800,
        ),
        headlineMedium: TextStyle(
          fontFamily: 'Roboto',
          color: navy,
          fontSize: 24,
          fontWeight: FontWeight.w800,
        ),
        titleLarge: TextStyle(
          fontFamily: 'Roboto',
          color: navy,
          fontSize: 19,
          fontWeight: FontWeight.w700,
        ),
        titleMedium: TextStyle(
          fontFamily: 'Roboto',
          color: navy,
          fontSize: 15,
          fontWeight: FontWeight.w700,
        ),
        bodyMedium: TextStyle(
          fontFamily: 'Roboto',
          color: navy,
          fontSize: 13,
          height: 1.4,
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: Colors.white,
        indicatorColor: const Color(0xFFE8F3FF),
        height: 68,
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => TextStyle(
            fontFamily: 'Roboto',
            fontSize: 11,
            color: states.contains(WidgetState.selected) ? blue : muted,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: const Color(0xFFF0F6FA),
        selectedColor: const Color(0xFFDDEEFF),
        side: BorderSide.none,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        isDense: true,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 14,
          vertical: 14,
        ),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: Color(0xFFE2EAF1)),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: Color(0xFFE2EAF1)),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: blue,
          foregroundColor: Colors.white,
          minimumSize: const Size(0, 48),
          textStyle: const TextStyle(
            fontFamily: 'Roboto',
            fontWeight: FontWeight.w700,
            fontSize: 14,
          ),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(10),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(10),
          ),
        ),
      ),
    ),
    home: CustomerHome(api: api),
  );
}

class CustomerHome extends StatefulWidget {
  const CustomerHome({super.key, this.api});
  final CustomerApi? api;
  @override
  State<CustomerHome> createState() => _CustomerHomeState();
}

class _CustomerHomeState extends State<CustomerHome> {
  late final api = widget.api ?? CustomerApi.configured();
  late final cart = ShoppingCart(api.storage, 'customer:${api.baseUrl}:bag');
  late final notifications = MobileNotifications(
    audience: 'customer',
    request: (method, path, [body]) =>
        api.request(method, 'customer/$path', body),
    readStorage: api.storage.read,
    writeStorage: api.storage.write,
    signedIn: () => api.signedIn,
    openOrder: openNotificationOrder,
    foreground: (message) {
      if (mounted && api.signedIn) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(message)));
      }
    },
  );
  String? selectedAddressId;
  Future<void> openNotificationOrder(String reference) async {
    if (!mounted || !api.signedIn) return;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) =>
            CustomerOrderPage(api: api, reference: reference, reorder: reorder),
      ),
    );
    if (mounted) await loadAccount();
  }

  Future<void> inbox() async {
    if (!await signIn() || !mounted) return;
    await notifications.resume();
    if (!mounted) return;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => NotificationInbox(notifications: notifications),
      ),
    );
    syncSession();
  }

  Catalogue? catalogue;
  CustomerProfile? profile;
  List<CustomerOrder> orders = [];
  String? pin,
      locationLabel,
      category,
      catalogueError,
      accountError,
      startupError;
  int? subcategory;
  String shoppingSection = 'food';
  String search = '';
  final searchInput = TextEditingController();
  int tab = 0;
  bool starting = true, fetching = false, accountBusy = false;
  String get pinKey => 'customer:${api.baseUrl}:pin';
  @override
  void initState() {
    super.initState();
    initialize();
  }

  @override
  void dispose() {
    searchInput.dispose();
    notifications.dispose();
    cart.dispose();
    if (widget.api == null) api.close();
    super.dispose();
  }

  Future<void> initialize() async {
    setState(() {
      starting = true;
      startupError = null;
      tab = 0;
      category = null;
      subcategory = null;
      search = '';
      searchInput.clear();
    });
    try {
      await api.restore();
      await cart.restore();
      pin = await api.storage.read(pinKey);
      locationLabel = await api.storage.read('$pinKey:label');
      unawaited(notifications.resume().catchError((_) {}));
    } catch (e) {
      startupError = friendlyError(e);
    }
    if (!mounted) return;
    setState(() => starting = false);
    if (startupError == null) {
      await Future.wait([loadCatalogue(), if (api.signedIn) loadAccount()]);
    }
  }

  Future<void> loadCatalogue() async {
    if (fetching) return;
    setState(() {
      fetching = true;
      catalogueError = null;
    });
    try {
      final locality = await api.storage.read('$pinKey:locality');
      final result = await api.catalogue(pin, locality: locality);
      if (mounted) setState(() => catalogue = result);
    } catch (e) {
      if (mounted) {
        setState(() {
          catalogueError = friendlyError(e);
          catalogue = null;
        });
      }
    } finally {
      if (mounted) setState(() => fetching = false);
    }
  }

  void syncSession() {
    if (!api.signedIn && mounted) {
      setState(() {
        profile = null;
        orders = [];
      });
    }
  }

  Future<void> loadAccount() async {
    if (accountBusy) return;
    if (!api.signedIn) {
      syncSession();
      return;
    }
    setState(() {
      accountBusy = true;
      accountError = null;
    });
    try {
      final customer = await api.profile();
      final history = await api.orders();
      if (mounted) {
        setState(() {
          profile = customer;
          orders = history;
        });
      }
    } catch (e) {
      syncSession();
      if (mounted) setState(() => accountError = friendlyError(e));
    } finally {
      if (mounted) setState(() => accountBusy = false);
    }
  }

  Future<bool> signIn({bool forCheckout = false}) async {
    if (api.signedIn) await loadAccount();
    if (!mounted) return false;
    if (!api.signedIn) {
      final success = await Navigator.of(context).push<bool>(
        MaterialPageRoute(
          builder: (_) => CustomerLogin(api: api, forCheckout: forCheckout),
        ),
      );
      if (!mounted || success != true) return false;
    }
    await loadAccount();
    if (!mounted || profile == null) return false;
    if (profile!.demo) {
      notifyError(
        context,
        const ApiError(
          'Sign in with a verified customer account to place real orders.',
        ),
      );
      return false;
    }
    return true;
  }

  Future<CustomerProfile?> addresses() async {
    if (!await signIn() || !mounted) return null;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => AddressBook(api: api, profile: profile!),
      ),
    );
    if (!mounted) return null;
    await loadAccount();
    return profile;
  }

  Future<void> checkout() async {
    if (!await signIn(forCheckout: true) || !mounted) return;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => CheckoutPage(
          api: api,
          profile: profile!,
          cart: cart,
          manageAddresses: addresses,
          initialAddressId: selectedAddressId,
        ),
      ),
    );
    if (mounted) await loadAccount();
  }

  Future<void> bag() async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => BagPage(
          cart: cart,
          checkout: checkout,
          api: api,
          profile: profile,
          manageAddresses: addresses,
          onAddressSelected: (id) => selectedAddressId = id,
        ),
      ),
    );
    syncSession();
  }

  Future<void> choosePin() async {
    Map<String, dynamic> chosen = {};
    final result = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, update) => AlertDialog(
          title: const Text('Select delivery location'),
          content: SizedBox(
            width: 360,
            child: SingleChildScrollView(
              child: LocationSelector(
                api: api,
                onChanged: (value) => update(() => chosen = value),
              ),
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: chosen['locality_id'] == null
                  ? null
                  : () => Navigator.pop(context, chosen),
              child: const Text('Use location'),
            ),
          ],
        ),
      ),
    );
    if (!mounted || result == null) return;
    if (cart.lines.isNotEmpty) {
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Change delivery area?'),
          content: const Text(
            'Your cart will be cleared so you can shop for the new area.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
            TextButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Change area'),
            ),
          ],
        ),
      );
      if (confirmed != true) return;
    }
    await cart.clear();
    await api.storage.write(pinKey, result['pin']);
    await api.storage.write(
      '$pinKey:label',
      '${result['locality']}, ${result['city']}',
    );
    await api.storage.write(
      '$pinKey:locality',
      result['locality_id'].toString(),
    );
    if (mounted) {
      setState(() {
        pin = result['pin'];
        locationLabel = '${result['locality']}, ${result['city']}';
        category = null;
        subcategory = null;
      });
    }
    await loadCatalogue();
  }

  Future<void> add(Product product, {int quantity = 1}) async {
    if (pin == null) {
      await choosePin();
      return;
    }
    try {
      if (product.optionGroups.isNotEmpty) {
        final configured = await chooseDishOptions(
          context,
          product,
          cart.lines[product.id],
        );
        if (configured == null) return;
        product = configured;
      }
      if (!mounted) return;
      if (cart.lines.isNotEmpty && cart.vendorId != product.vendorId) {
        final replace = await showDialog<bool>(
          context: context,
          builder: (context) => AlertDialog(
            title: const Text('Start a new bag?'),
            content: const Text(
              'This item belongs to another shop. Starting a new bag removes the items currently in your bag.',
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: const Text('Keep my bag'),
              ),
              FilledButton(
                onPressed: () => Navigator.pop(context, true),
                child: const Text('Start new bag'),
              ),
            ],
          ),
        );
        if (replace != true) return;
        await cart.clear();
      }
      final previous = cart.lines[product.id];
      final changedOptions =
          previous != null &&
          previous.product.optionIds.join('|') != product.optionIds.join('|');
      if (changedOptions && mounted) {
        final replace = await showDialog<bool>(
          context: context,
          builder: (context) => AlertDialog(
            title: const Text('Replace this item’s options?'),
            content: const Text(
              'This replaces the quantity and choices for this product in your bag.',
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: const Text('Keep item'),
              ),
              FilledButton(
                onPressed: () => Navigator.pop(context, true),
                child: const Text('Replace item'),
              ),
            ],
          ),
        );
        if (replace != true) return;
      }
      await cart.setQuantity(
        product,
        (changedOptions ? 0 : (cart.lines[product.id]?.quantity ?? 0)) +
            quantity,
      );
    } catch (e) {
      if (mounted) notifyError(context, e);
    }
  }

  Future<void> details(Product product) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (context) => Scaffold(
          appBar: AppBar(title: const Text('Product details')),
          bottomNavigationBar: SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: ProductPurchaseControls(
                product: product,
                cart: cart,
                onBuy: (quantity) => add(product, quantity: quantity),
              ),
            ),
          ),
          body: ShopBody(
            children: [
              ShopCard(
                child: Center(
                  child: LayoutBuilder(
                    builder: (context, constraints) => ProductImage(
                      product,
                      size: constraints.maxWidth.clamp(120, 310).toDouble(),
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 12),
              FavouriteButton(api: api, id: product.id),
              Text(
                'Diet: ${(product.json['dietary_label'] ?? 'unspecified').toString().replaceAll('_', ' ')}',
              ),
              Text(
                'Allergens reported by shop: ${(product.json['allergens'] as List? ?? []).join(', ')}. Ask the kitchen about allergies and cross-contact.',
              ),
              ProductReviews(api: api, id: product.id),
              Text(
                product.name,
                style: Theme.of(context).textTheme.headlineMedium,
              ),
              const SizedBox(height: 8),
              Text(
                product.pack,
                style: const TextStyle(fontFamily: 'Roboto', color: muted),
              ),
              const SizedBox(height: 16),
              Text(
                rupees(product.price),
                style: Theme.of(context).textTheme.headlineMedium,
              ),
              const Divider(height: 32, color: Color(0xFFE7EEF3)),
              if (product.description.isNotEmpty) ...[
                const SectionTitle('About this product'),
                Text(product.description),
                const SizedBox(height: 20),
              ],
              if ((product.json['care_instructions'] as String?)?.isNotEmpty ==
                  true)
                ShopCard(
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Icon(Icons.info_outline, color: blue, size: 20),
                      const SizedBox(width: 10),
                      Expanded(child: Text(product.json['care_instructions'])),
                    ],
                  ),
                ),
              if (product.image?.contains('/food-demo-dishes/') == true)
                TextButton(
                  onPressed: () async {
                    final filename = Uri.parse(
                      product.image!,
                    ).pathSegments.last.replaceFirst('.webp', '');
                    try {
                      final opened = await launchUrl(
                        Uri.parse(
                          'https://dropbell.in/food-photo-credits.html#$filename',
                        ),
                        mode: LaunchMode.externalApplication,
                      );
                      if (!opened && context.mounted) {
                        notifyError(
                          context,
                          const ApiError('Could not open photo credits.'),
                        );
                      }
                    } catch (e) {
                      if (context.mounted) notifyError(context, e);
                    }
                  },
                  child: const Text('Real dish photo · Photo credit'),
                ),
              if (product.sample)
                const Text(
                  'Sample product preview. This item cannot be ordered for real delivery.',
                ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> rename() async {
    final input = TextEditingController(text: profile!.name);
    final form = GlobalKey<FormState>();
    final value = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Your name'),
        content: Form(
          key: form,
          child: TextFormField(
            controller: input,
            maxLength: 60,
            decoration: const InputDecoration(labelText: 'Full name'),
            validator: (value) =>
                value?.trim().isEmpty != false ? 'Enter your name' : null,
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () {
              if (form.currentState!.validate()) {
                Navigator.pop(context, input.text.trim());
              }
            },
            child: const Text('Save'),
          ),
        ],
      ),
    );
    input.dispose();
    if (value == null || !mounted) return;
    setState(() => accountBusy = true);
    try {
      final updated = await api.rename(value);
      if (mounted) setState(() => profile = updated);
    } catch (e) {
      syncSession();
      if (mounted) notifyError(context, e);
    } finally {
      if (mounted) setState(() => accountBusy = false);
    }
  }

  Future<void> logout() async {
    setState(() => accountBusy = true);
    try {
      await api.logout();
      syncSession();
    } catch (e) {
      syncSession();
      if (mounted) notifyError(context, e);
    } finally {
      if (mounted) setState(() => accountBusy = false);
    }
  }

  Future<void> reorder(String reference) async {
    try {
      final identity = profile ?? await api.profile();
      final pending = CheckoutAttempt(api, identity.phone);
      await pending.restore();
      if (pending.pending != null) {
        throw const ApiError(
          'Recover your saved checkout before replacing the bag.',
        );
      }
      final locality = await api.storage.read('$pinKey:locality');
      if (locality == null) {
        throw const ApiError(
          'Choose your delivery location before reordering.',
        );
      }
      final result = (await api.request(
        'POST',
        'customer/orders/$reference/reorder',
        {'locality_id': int.parse(locality)},
      ))['data'];
      final items = result['items'] as List;
      if (items.isEmpty) {
        throw const ApiError(
          'These items are not currently available at your location.',
        );
      }
      if (!mounted) return;
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Order again?'),
          content: Text(
            'Replace your bag with ${items.length} available items? ${(result['unavailable'] as List).length} items could not be reordered. Current prices apply.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Replace bag'),
            ),
          ],
        ),
      );
      if (confirmed != true) return;
      final replacement = <int, CartLine>{
        for (final row in items)
          row['product']['id']: CartLine(
            Product(Map<String, dynamic>.from(row['product'])),
            row['quantity'],
          ),
      };
      await cart.storage.write(
        cart.key,
        cart.encode(replacement, cart.consumed),
      );
      await cart.restore();
      if (mounted) await bag();
    } catch (e) {
      if (mounted) notifyError(context, e);
    }
  }

  Future<void> favourites() async {
    if (!await signIn() || !mounted) return;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => FavouritesPage(
          api: api,
          open: (row) async {
            if (pin == null) {
              await choosePin();
              return;
            }
            if (row['kind'] == 'vendor') {
              await Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => RestaurantMenuPage(
                    api: api,
                    restaurant: {'id': row['target_id'], 'name': row['name']},
                    pin: pin!,
                    cart: cart,
                    add: (product, quantity) =>
                        add(product, quantity: quantity),
                    details: details,
                    openBag: bag,
                  ),
                ),
              );
            } else {
              final fresh = await api.catalogue(
                pin,
                locality: await api.storage.read('$pinKey:locality'),
              );
              final product = fresh.products
                  .where((p) => p.id == row['target_id'])
                  .firstOrNull;
              if (product == null) {
                throw const ApiError(
                  'This product is unavailable for your location.',
                );
              }
              await details(product);
            }
          },
        ),
      ),
    );
  }

  Future<void> openOrder(CustomerOrder order) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => CustomerOrderPage(
          api: api,
          reference: order.id,
          initial: order,
          reorder: reorder,
        ),
      ),
    );
    if (mounted) await loadAccount();
  }

  @override
  Widget build(BuildContext context) => starting
      ? const Scaffold(body: BrandLoading())
      : AnimatedBuilder(
          animation: cart,
          builder: (context, _) => Scaffold(
            appBar: AppBar(
              toolbarHeight: tab < 2 ? 100 : 56,
              title: tab < 2
                  ? Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Semantics(
                          button: true,
                          label: 'Choose delivery location',
                          child: InkWell(
                            onTap: fetching ? null : choosePin,
                            borderRadius: BorderRadius.circular(8),
                            child: SizedBox(
                              height: 36,
                              child: Row(
                                children: [
                                  const Icon(
                                    Icons.location_on_outlined,
                                    color: Colors.white,
                                    size: 21,
                                  ),
                                  const SizedBox(width: 6),
                                  Expanded(
                                    child: Text(
                                      locationLabel ?? 'Choose location',
                                      maxLines: 2,
                                      overflow: TextOverflow.ellipsis,
                                      style: const TextStyle(
                                        color: Colors.white,
                                        fontSize: 14,
                                        fontWeight: FontWeight.w600,
                                      ),
                                    ),
                                  ),
                                  const Icon(
                                    Icons.keyboard_arrow_down,
                                    color: Colors.white,
                                    size: 20,
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                        const SizedBox(height: 4),
                        if (tab == 0)
                          const DropbellLogo(width: 156, inverse: true)
                        else
                          Padding(
                            padding: const EdgeInsets.symmetric(vertical: 10),
                            child: Text(
                              category ?? 'Categories',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                      ],
                    )
                  : Text(tab == 2 ? 'My Orders' : 'My Account'),
              bottom: tab < 2
                  ? PreferredSize(
                      preferredSize: const Size.fromHeight(60),
                      child: Padding(
                        padding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
                        child: TextField(
                          controller: searchInput,
                          onChanged: (value) => setState(() => search = value),
                          decoration: InputDecoration(
                            hintText: tab == 0 || category == null
                                ? 'Search categories'
                                : 'Search products',
                            hintStyle: const TextStyle(
                              fontFamily: 'Roboto',
                              fontSize: 12,
                              color: muted,
                            ),
                            suffixIcon: const Icon(Icons.search, color: blue),
                            contentPadding: const EdgeInsets.symmetric(
                              horizontal: 12,
                              vertical: 12,
                            ),
                          ),
                        ),
                      ),
                    )
                  : null,
              actions: [
                IconButton(
                  onPressed: inbox,
                  tooltip: 'Notifications',
                  icon: const Icon(Icons.notifications_outlined),
                ),
                IconButton(
                  onPressed: bag,
                  tooltip: 'Open bag',
                  icon: Badge(
                    isLabelVisible: cart.count > 0,
                    label: Text('${cart.count}'),
                    child: const Icon(Icons.shopping_bag_outlined),
                  ),
                ),
              ],
            ),
            bottomNavigationBar: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (tab < 2 && cart.count > 0)
                  Container(
                    margin: const EdgeInsets.fromLTRB(16, 8, 16, 4),
                    child: FilledButton(
                      onPressed: bag,
                      child: Padding(
                        padding: const EdgeInsets.symmetric(vertical: 12),
                        child: Row(
                          children: [
                            const Icon(Icons.shopping_bag_outlined, size: 20),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(
                                '${cart.count} items · ${rupees(cart.estimate)}',
                              ),
                            ),
                            const Text('View cart'),
                            const SizedBox(width: 8),
                            const Icon(Icons.arrow_forward, size: 18),
                          ],
                        ),
                      ),
                    ),
                  ),
                NavigationBar(
                  selectedIndex: tab,
                  onDestinationSelected: (value) {
                    setState(() {
                      tab = value;
                      if (value < 2) {
                        category = null;
                        subcategory = null;
                        search = '';
                        searchInput.clear();
                      }
                    });
                    if ([2, 3].contains(value)) loadAccount();
                  },
                  destinations: const [
                    NavigationDestination(
                      icon: Icon(Icons.home_outlined),
                      selectedIcon: Icon(Icons.home),
                      label: 'Home',
                    ),
                    NavigationDestination(
                      icon: Icon(Icons.grid_view_outlined),
                      label: 'Categories',
                    ),
                    NavigationDestination(
                      icon: Icon(Icons.receipt_long_outlined),
                      label: 'Orders',
                    ),
                    NavigationDestination(
                      icon: Icon(Icons.person_outline),
                      label: 'Account',
                    ),
                  ],
                ),
              ],
            ),
            body: starting
                ? const BrandLoading()
                : startupError != null
                ? ShopBody(
                    children: [ErrorBox(startupError!, retry: initialize)],
                  )
                : ShopBody(
                    refresh: tab < 2 ? loadCatalogue : loadAccount,
                    children: [
                      if (tab < 2) sectionTabs(),
                      if (tab == 0)
                        PromotionCarousel(
                          key: ValueKey(shoppingSection),
                          api: api,
                          section: shoppingSection,
                        ),
                      if (tab < 2) ...catalogueWidgets(),
                      if (tab >= 2) ...[
                        if (accountBusy) const LinearProgressIndicator(),
                        if (accountError != null)
                          ErrorBox(
                            accountError!,
                            retry: accountBusy ? null : loadAccount,
                          ),
                        if (!api.signedIn) ...[
                          const SizedBox(height: 40),
                          const Icon(
                            Icons.person_outline,
                            color: blue,
                            size: 72,
                          ),
                          const SizedBox(height: 20),
                          Text(
                            tab == 2
                                ? 'All your orders, in one place.'
                                : 'Make yourself at home.',
                            style: Theme.of(context).textTheme.headlineMedium,
                          ),
                          const SizedBox(height: 16),
                          const Text(
                            'Sign in to manage your addresses, orders and account.',
                          ),
                          const SizedBox(height: 24),
                          FilledButton(
                            onPressed: signIn,
                            child: const Text('Sign in'),
                          ),
                        ] else if (tab == 2) ...[
                          Text(
                            'Your orders',
                            style: Theme.of(context).textTheme.headlineMedium,
                          ),
                          const SizedBox(height: 16),
                          if (orders.isEmpty &&
                              !accountBusy &&
                              accountError == null)
                            const ShopCard(
                              child: Text(
                                'Your first order is waiting to happen. Explore the store and add your everyday favourites.',
                              ),
                            ),
                          for (final order in orders)
                            ShopCard(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    order.status
                                        .replaceAll('_', ' ')
                                        .toUpperCase(),
                                    style: Theme.of(
                                      context,
                                    ).textTheme.titleMedium,
                                  ),
                                  if (order.sample)
                                    const Text('Sample order • Test only'),
                                  const SizedBox(height: 8),
                                  Text(
                                    '${order.items.length} products · ${rupees(order.total)}',
                                  ),
                                  Text(order.json['delivery']),
                                  const SizedBox(height: 12),
                                  FilledButton(
                                    onPressed: () => openOrder(order),
                                    child: const Text('View order'),
                                  ),
                                ],
                              ),
                            ),
                        ] else if (profile != null) ...[
                          Text(
                            'Your account',
                            style: Theme.of(context).textTheme.headlineMedium,
                          ),
                          const SizedBox(height: 16),
                          ShopCard(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const CircleAvatar(
                                  radius: 30,
                                  child: Icon(Icons.person, size: 34),
                                ),
                                const SizedBox(height: 18),
                                Text(
                                  profile!.name,
                                  style: Theme.of(context).textTheme.titleLarge,
                                ),
                                Text('+91 ${profile!.phone}'),
                                TextButton(
                                  onPressed: accountBusy ? null : rename,
                                  child: const Text('Edit name'),
                                ),
                              ],
                            ),
                          ),
                          ShopCard(
                            child: Column(
                              children: [
                                ListTile(
                                  contentPadding: EdgeInsets.zero,
                                  leading: const Icon(
                                    Icons.location_on_outlined,
                                    color: blue,
                                  ),
                                  title: const Text('Saved addresses'),
                                  trailing: const Icon(Icons.chevron_right),
                                  onTap: accountBusy ? null : addresses,
                                ),
                                ListTile(
                                  contentPadding: EdgeInsets.zero,
                                  leading: const Icon(
                                    Icons.receipt_long_outlined,
                                    color: blue,
                                  ),
                                  title: const Text('Transactions'),
                                  trailing: const Icon(Icons.chevron_right),
                                  onTap: () async {
                                    await Navigator.of(context).push(
                                      MaterialPageRoute<void>(
                                        builder: (_) =>
                                            TransactionsPage(api: api),
                                      ),
                                    );
                                    syncSession();
                                  },
                                ),
                                ListTile(
                                  contentPadding: EdgeInsets.zero,
                                  leading: const Icon(
                                    Icons.support_agent,
                                    color: blue,
                                  ),
                                  title: const Text('Customer Support'),
                                  trailing: const Icon(Icons.chevron_right),
                                  onTap: () => Navigator.of(context).push(
                                    MaterialPageRoute<void>(
                                      builder: (_) =>
                                          CustomerSupportPage(api: api),
                                    ),
                                  ),
                                ),
                                ListTile(
                                  contentPadding: EdgeInsets.zero,
                                  leading: const Icon(
                                    Icons.help_outline,
                                    color: blue,
                                  ),
                                  title: const Text('Returns & refunds'),
                                  subtitle: const Text(
                                    'Open an order to submit a request',
                                  ),
                                  onTap: () => setState(() => tab = 2),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(height: 16),
                          OutlinedButton(
                            onPressed: favourites,
                            child: const Text('My favourites'),
                          ),
                          OutlinedButton(
                            onPressed: checkout,
                            child: const Text(
                              'Recover an unconfirmed checkout',
                            ),
                          ),
                          const SizedBox(height: 12),
                          OutlinedButton(
                            onPressed: accountBusy ? null : logout,
                            child: const Text('Sign out'),
                          ),
                        ],
                      ],
                    ],
                  ),
          ),
        );
  Widget sectionTabs() => Padding(
    padding: const EdgeInsets.only(bottom: 18),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        for (final section in ['food', 'groceries'])
          Expanded(
            child: Semantics(
              selected: shoppingSection == section,
              child: FilledButton.icon(
                key: ValueKey('section-$section'),
                style: FilledButton.styleFrom(
                  backgroundColor: section == 'food'
                      ? const Color(0xFFED3346)
                      : const Color(0xFF22A65A),
                  foregroundColor: Colors.white,
                  minimumSize: Size(0, shoppingSection == section ? 54 : 44),
                  shape: const RoundedRectangleBorder(
                    borderRadius: BorderRadius.vertical(
                      top: Radius.circular(12),
                    ),
                  ),
                  side: shoppingSection == section
                      ? const BorderSide(color: navy, width: 2)
                      : BorderSide.none,
                ),
                onPressed: () => setState(() {
                  shoppingSection = section;
                  category = null;
                  subcategory = null;
                  search = '';
                  searchInput.clear();
                }),
                icon: Icon(
                  shoppingSection == section
                      ? Icons.check_circle
                      : section == 'food'
                      ? Icons.restaurant
                      : Icons.shopping_basket_outlined,
                  size: 18,
                ),
                label: Text(section == 'food' ? 'Food' : 'Groceries'),
              ),
            ),
          ),
      ],
    ),
  );

  List<Widget> restaurantWidgets() {
    final term = search.trim().toLowerCase();
    final restaurants = (catalogue?.vendors ?? <Map<String, dynamic>>[])
        .where(
          (v) =>
              (v['sections'] as List? ?? []).contains('food') &&
              '${v['name']} ${(v['cuisines'] as List? ?? []).join(' ')}'
                  .toLowerCase()
                  .contains(term),
        )
        .toList();
    return [
      const SectionTitle('Restaurants near you'),
      if (pin == null)
        ShopCard(
          child: TextButton.icon(
            onPressed: choosePin,
            icon: const Icon(Icons.location_on_outlined),
            label: const Text('Choose your delivery location'),
          ),
        )
      else if (!fetching && catalogueError == null && restaurants.isEmpty)
        const ShopCard(
          child: Text(
            'No restaurants match this location or search. Try another cuisine or delivery area.',
          ),
        )
      else
        for (final restaurant in restaurants)
          ShopCard(
            child: ListTile(
              contentPadding: EdgeInsets.zero,
              leading: SizedBox(
                width: 64,
                height: 64,
                child: restaurant['image_url'] is String
                    ? ClipRRect(
                        borderRadius: BorderRadius.circular(8),
                        child: Image.network(
                          restaurant['image_url'],
                          fit: BoxFit.cover,
                          errorBuilder: (_, _, _) =>
                              const Icon(Icons.restaurant, color: blue),
                        ),
                      )
                    : const Icon(Icons.restaurant, color: blue),
              ),
              title: Text(restaurant['name']),
              subtitle: Text(
                '${(restaurant['cuisines'] as List? ?? []).join(' · ')}\n${restaurant['section_counts']?['food'] ?? restaurant['product_count']} dishes · ${restaurant['city']}\n${restaurant['ordering_status'] == 'busy'
                    ? 'Busy · orders paused'
                    : restaurant['ordering_status'] == 'closed'
                    ? 'Currently closed'
                    : 'Preparation: about ${restaurant['preparation_minutes'] ?? 25} min'}',
              ),
              trailing: const Icon(Icons.chevron_right),
              onTap: () async {
                await Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => RestaurantMenuPage(
                      api: api,
                      restaurant: restaurant,
                      pin: pin!,
                      cart: cart,
                      add: (product, quantity) =>
                          add(product, quantity: quantity),
                      details: details,
                      openBag: bag,
                    ),
                  ),
                );
              },
            ),
          ),
      const SizedBox(height: 18),
    ];
  }

  List<Widget> catalogueWidgets() {
    final products =
        catalogue?.products
            .where(
              (p) =>
                  p.section == shoppingSection &&
                  (category == null || p.category == category) &&
                  (subcategory == null || p.subcategory == subcategory) &&
                  '${p.name} ${p.category}'.toLowerCase().contains(
                    search.toLowerCase(),
                  ),
            )
            .toList() ??
        [];
    final subcategories =
        catalogue?.subcategories
            .where(
              (s) => catalogue!.products.any(
                (p) =>
                    p.section == shoppingSection &&
                    p.subcategory == s['id'] &&
                    (category == null || p.category == category),
              ),
            )
            .toList() ??
        [];
    final names = (catalogue?.categoriesFor(shoppingSection) ?? <String>[])
        .where(
          (name) => name.toLowerCase().contains(search.toLowerCase().trim()),
        )
        .toList();
    final showCategories = tab == 0 || category == null;
    return [
      if (fetching) const LinearProgressIndicator(),
      if (catalogueError != null)
        ErrorBox(catalogueError!, retry: fetching ? null : loadCatalogue),
      if (showCategories) ...[
        Row(
          children: [
            Expanded(
              child: SectionTitle(
                shoppingSection == 'food'
                    ? 'What are you craving?'
                    : 'Shop by category',
              ),
            ),
            if (tab == 0)
              TextButton(
                onPressed: () => setState(() => tab = 1),
                child: const Text('View all →'),
              ),
          ],
        ),
        if (names.isEmpty && products.isEmpty && catalogue != null)
          const ShopCard(
            child: Text('No matching categories. Try another search.'),
          ),
      ],
      if (showCategories && names.isNotEmpty) ...[
        LayoutBuilder(
          builder: (context, constraints) {
            final columns = tab == 0
                ? (constraints.maxWidth > 560 ? 6 : 3)
                : (constraints.maxWidth > 560 ? 3 : 2);
            final width = (constraints.maxWidth - (columns - 1) * 10) / columns;
            return Wrap(
              spacing: 10,
              runSpacing: 12,
              children: [
                for (final name in names)
                  SizedBox(
                    width: width,
                    child: CategoryTile(
                      name: name,
                      imageUrl: catalogue?.categoryDetails
                          .where((c) => c['name'] == name)
                          .firstOrNull?['image_url'],
                      large: tab != 0,
                      onTap: () => setState(() {
                        category = name;
                        subcategory = null;
                        tab = 1;
                        search = '';
                        searchInput.clear();
                      }),
                    ),
                  ),
              ],
            );
          },
        ),
        const SizedBox(height: 22),
      ],
      if (tab == 0 && shoppingSection == 'food') ...restaurantWidgets(),
      if (!showCategories || search.trim().isNotEmpty) ...[
        Row(
          children: [
            Expanded(
              child: SectionTitle(
                category ??
                    (search.isNotEmpty
                        ? 'Search results'
                        : 'Everyday favourites'),
              ),
            ),
            if (category != null)
              TextButton(
                onPressed: () => setState(() {
                  category = null;
                  subcategory = null;
                  search = '';
                  searchInput.clear();
                }),
                child: const Text('All categories'),
              ),
          ],
        ),
        if (category != null && subcategories.isNotEmpty)
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: ChoiceChip(
                    label: const Text('All'),
                    selected: subcategory == null,
                    onSelected: (_) => setState(() => subcategory = null),
                  ),
                ),
                for (final sub in subcategories)
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      label: Text(sub['name']),
                      selected: subcategory == sub['id'],
                      onSelected: (_) =>
                          setState(() => subcategory = sub['id']),
                    ),
                  ),
              ],
            ),
          ),
        if (catalogue != null && products.isEmpty)
          const ShopCard(
            child: Text(
              'No matching products in this area. Try another category or delivery location.',
            ),
          ),
        for (final product in products)
          ProductTile(
            product: product,
            cart: cart,
            hasPin: pin != null,
            open: () => details(product),
            add: (quantity) => add(product, quantity: quantity),
          ),
      ],
    ];
  }
}

class ProductTile extends StatelessWidget {
  const ProductTile({
    super.key,
    required this.product,
    required this.cart,
    required this.hasPin,
    required this.open,
    required this.add,
  });
  final Product product;
  final ShoppingCart cart;
  final bool hasPin;
  final VoidCallback open;
  final Future<void> Function(int) add;
  @override
  Widget build(BuildContext context) => ShopCard(
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        InkWell(onTap: open, child: ProductImage(product, size: 86)),
        const SizedBox(width: 14),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              InkWell(
                onTap: open,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      product.name,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: 4),
                    if (product.section == 'food')
                      Text(
                        (product.json['dietary_label'] ?? 'Diet not specified')
                            .toString()
                            .replaceAll('_', ' '),
                      ),
                    if ((product.json['review_count'] as int? ?? 0) > 0)
                      Text(
                        '★ ${product.json['rating']}/5 · ${product.json['review_count']} reviews',
                      ),
                    Text(
                      product.pack,
                      style: const TextStyle(color: muted, fontSize: 12),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 8),
              Text(
                rupees(product.price),
                style: const TextStyle(
                  fontWeight: FontWeight.w700,
                  color: navy,
                ),
              ),
              const SizedBox(height: 10),
              ProductPurchaseControls(
                key: ValueKey(product.id),
                product: product,
                cart: cart,
                onBuy: add,
              ),
            ],
          ),
        ),
      ],
    ),
  );
}

class ProductPurchaseControls extends StatefulWidget {
  const ProductPurchaseControls({
    super.key,
    required this.product,
    required this.cart,
    required this.onBuy,
  });
  final Product product;
  final ShoppingCart cart;
  final Future<void> Function(int) onBuy;
  @override
  State<ProductPurchaseControls> createState() =>
      _ProductPurchaseControlsState();
}

class _ProductPurchaseControlsState extends State<ProductPurchaseControls> {
  int quantity = 1;
  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: widget.cart,
    builder: (context, _) {
      final inCart = widget.cart.vendorId == widget.product.vendorId
          ? (widget.cart.lines[widget.product.id]?.quantity ?? 0)
          : 0;
      final remaining = (widget.product.maxQuantity - inCart).clamp(
        0,
        widget.product.maxQuantity,
      );
      final selected = quantity.clamp(1, remaining > 0 ? remaining : 1);
      final available =
          widget.product.available && !widget.cart.busy && remaining > 0;
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          FilledButton(
            onPressed: available ? () => widget.onBuy(selected) : null,
            child: Text(
              widget.product.sample
                  ? 'Preview only'
                  : !widget.product.available
                  ? 'Unavailable'
                  : remaining == 0
                  ? 'Limit reached'
                  : 'Buy',
            ),
          ),
          const SizedBox(height: 6),
          Row(
            children: [
              const Expanded(
                child: Text(
                  'Quantity',
                  style: TextStyle(fontSize: 12, color: muted),
                ),
              ),
              IconButton(
                tooltip: 'Decrease quantity for ${widget.product.name}',
                onPressed: available && selected > 1
                    ? () => setState(() => quantity = selected - 1)
                    : null,
                icon: const Icon(Icons.remove),
                visualDensity: VisualDensity.compact,
              ),
              Semantics(
                label: 'Quantity for ${widget.product.name}',
                value: '$selected',
                child: Text(
                  '$selected',
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
              ),
              IconButton(
                tooltip: 'Increase quantity for ${widget.product.name}',
                onPressed: available && selected < remaining
                    ? () => setState(() => quantity = selected + 1)
                    : null,
                icon: const Icon(Icons.add),
                visualDensity: VisualDensity.compact,
              ),
            ],
          ),
          if (inCart > 0)
            Text(
              '$inCart in cart',
              style: const TextStyle(fontSize: 12, color: blue),
            ),
        ],
      );
    },
  );
}

class RestaurantMenuPage extends StatefulWidget {
  const RestaurantMenuPage({
    super.key,
    required this.api,
    required this.restaurant,
    required this.pin,
    required this.cart,
    required this.add,
    required this.details,
    required this.openBag,
  });
  final CustomerApi api;
  final Map<String, dynamic> restaurant;
  final String pin;
  final ShoppingCart cart;
  final Future<void> Function(Product, int) add;
  final Future<void> Function(Product) details;
  final Future<void> Function() openBag;
  @override
  State<RestaurantMenuPage> createState() => _RestaurantMenuPageState();
}

class _RestaurantMenuPageState extends State<RestaurantMenuPage> {
  Catalogue? menu;
  String? error;
  String search = '';
  bool loading = true;
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final locality = await widget.api.storage.read(
        'customer:${widget.api.baseUrl}:pin:locality',
      );
      final result = await widget.api.catalogue(
        widget.pin,
        locality: locality,
        vendorId: widget.restaurant['id'],
      );
      if (mounted) setState(() => menu = result);
    } catch (e) {
      if (mounted) {
        setState(() {
          error = friendlyError(e);
          menu = null;
        });
      }
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final products = (menu?.products ?? <Product>[])
        .where(
          (p) =>
              p.section == 'food' &&
              '${p.name} ${p.category}'.toLowerCase().contains(
                search.trim().toLowerCase(),
              ),
        )
        .toList();
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.restaurant['name']),
        actions: [
          AnimatedBuilder(
            animation: widget.cart,
            builder: (context, _) => TextButton.icon(
              onPressed: widget.openBag,
              icon: const Icon(
                Icons.shopping_bag_outlined,
                color: Colors.white,
              ),
              label: Text(
                '${widget.cart.count}',
                style: const TextStyle(color: Colors.white),
              ),
            ),
          ),
        ],
      ),
      body: ShopBody(
        refresh: load,
        children: [
          FavouriteButton(
            api: widget.api,
            id: widget.restaurant['id'],
            kind: 'vendor',
          ),
          Text((widget.restaurant['cuisines'] as List? ?? []).join(' · ')),
          Text(
            widget.restaurant['ordering_status'] == 'busy'
                ? 'This restaurant is busy. New orders are paused.'
                : widget.restaurant['ordering_status'] == 'closed'
                ? 'This restaurant is currently closed.'
                : 'Preparation estimate: about ${widget.restaurant['preparation_minutes'] ?? 25} minutes.',
          ),
          const SizedBox(height: 12),
          TextField(
            decoration: const InputDecoration(
              labelText: 'Search this menu',
              prefixIcon: Icon(Icons.search),
            ),
            onChanged: (value) => setState(() => search = value),
          ),
          const SizedBox(height: 16),
          if (loading) const LinearProgressIndicator(),
          if (error != null) ErrorBox(error!, retry: loading ? null : load),
          if (!loading && error == null && products.isEmpty)
            const ShopCard(
              child: Text(
                'No available dishes match this menu. Try another search or pull down to refresh.',
              ),
            ),
          for (final product in products)
            ProductTile(
              product: product,
              cart: widget.cart,
              hasPin: true,
              open: () => widget.details(product),
              add: (quantity) => widget.add(product, quantity),
            ),
        ],
      ),
    );
  }
}
