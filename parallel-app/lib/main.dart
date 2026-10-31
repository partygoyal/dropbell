import 'app_recovery.dart';
import 'package:flutter/material.dart';
import 'delivery.dart';
import 'rider_app.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(RecoverableApp(builder: () => const PartnerApp()));
}

const navy = Color(0xFF091B51);
const blue = Color(0xFF0861FF);

class PartnerApp extends StatelessWidget {
  const PartnerApp({super.key, this.demo = false});
  final bool demo;
  @override
  Widget build(BuildContext context) => MaterialApp(
    title: 'Dropbell Partner',
    debugShowCheckedModeBanner: false,
    scrollBehavior: const MaterialScrollBehavior().copyWith(overscroll: false),
    theme: ThemeData(
      useMaterial3: true,
      colorScheme: ColorScheme.fromSeed(seedColor: blue, primary: blue),
      scaffoldBackgroundColor: const Color(0xFFF5F8FF),
      appBarTheme: const AppBarTheme(
        backgroundColor: Color(0xFFF5F8FF),
        foregroundColor: navy,
      ),
      textTheme: const TextTheme(
        headlineMedium: TextStyle(color: navy, fontWeight: FontWeight.w800),
        titleLarge: TextStyle(color: navy, fontWeight: FontWeight.w800),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(double.infinity, 52),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
          ),
        ),
      ),
      inputDecorationTheme: const InputDecorationTheme(
        border: OutlineInputBorder(),
      ),
    ),
    home: demo
        ? const PartnerHome()
        : RiderEntry(demoBuilder: () => const PartnerHome()),
  );
}

class PartnerHome extends StatefulWidget {
  const PartnerHome({super.key});
  @override
  State<PartnerHome> createState() => _PartnerHomeState();
}

class _PartnerHomeState extends State<PartnerHome> {
  final deliveries = demoDeliveries();
  bool entered = false;
  bool online = true;
  int tab = 0;

  Future<void> openDelivery(Delivery delivery) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => DeliveryScreen(delivery: delivery),
      ),
    );
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    if (!entered) {
      return Scaffold(
        body: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(28),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 480),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'dropbell',
                      style: TextStyle(
                        fontSize: 46,
                        fontWeight: FontWeight.w900,
                        color: navy,
                        letterSpacing: -2,
                      ),
                    ),
                    const Text(
                      'DELIVERY PARTNER',
                      style: TextStyle(
                        color: blue,
                        letterSpacing: 3,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 48),
                    Container(
                      height: 170,
                      width: double.infinity,
                      decoration: BoxDecoration(
                        color: const Color(0xFFE4EEFF),
                        borderRadius: BorderRadius.circular(28),
                      ),
                      child: const Icon(
                        Icons.delivery_dining_rounded,
                        size: 130,
                        color: blue,
                      ),
                    ),
                    const SizedBox(height: 32),
                    Text(
                      'Good things\nstart with you.',
                      style: Theme.of(context).textTheme.headlineMedium,
                    ),
                    const SizedBox(height: 14),
                    const Text(
                      'Deliver everyday essentials. Make every doorstep a little happier.',
                      style: TextStyle(
                        fontSize: 17,
                        height: 1.5,
                        color: Color(0xFF607099),
                      ),
                    ),
                    const SizedBox(height: 32),
                    FilledButton(
                      onPressed: () => setState(() => entered = true),
                      child: const Text('Explore rider demo →'),
                    ),
                    const SizedBox(height: 16),
                    const Text(
                      'Preview only • Sample orders and profile\nNo real sign-in, payments or delivery updates.',
                      style: TextStyle(color: Color(0xFF607099), height: 1.5),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      );
    }
    final active = deliveries.where((d) => !d.delivered).toList();
    final completed = deliveries.where((d) => d.delivered).toList();
    return Scaffold(
      appBar: AppBar(
        title: const Text(
          'dropbell partner',
          style: TextStyle(fontWeight: FontWeight.w900),
        ),
        actions: const [
          Padding(
            padding: EdgeInsets.only(right: 18),
            child: Chip(label: Text('DEMO')),
          ),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: tab,
        onDestinationSelected: (value) => setState(() => tab = value),
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.assignment_outlined),
            label: 'Assigned',
          ),
          NavigationDestination(icon: Icon(Icons.history), label: 'History'),
          NavigationDestination(
            icon: Icon(Icons.person_outline),
            label: 'Account',
          ),
        ],
      ),
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 640),
            child: ListView(
              padding: const EdgeInsets.all(20),
              children: [
                if (tab == 0) ...[
                  Text(
                    'Hello, Arjun 👋',
                    style: Theme.of(context).textTheme.headlineMedium,
                  ),
                  const SizedBox(height: 6),
                  const Text(
                    'Every drop, delivered with care.',
                    style: TextStyle(color: Color(0xFF607099)),
                  ),
                  const SizedBox(height: 22),
                  Panel(
                    child: SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      title: Text(online ? 'You’re online' : 'You’re offline'),
                      subtitle: Text(
                        online
                            ? 'Ready for your assigned deliveries'
                            : 'Go online to continue your deliveries',
                      ),
                      value: online,
                      onChanged: (value) => setState(() => online = value),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Expanded(
                        child: Metric(
                          value: '${active.length}',
                          label: 'Assigned orders',
                          icon: Icons.shopping_bag_outlined,
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Metric(
                          value: '${completed.length}',
                          label: 'Completed',
                          icon: Icons.task_alt,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 26),
                  Text(
                    'Your deliveries',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const SizedBox(height: 12),
                  if (active.isEmpty)
                    const Panel(
                      child: Text('All done! You have no pending deliveries.'),
                    ),
                  for (final delivery in active)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 14),
                      child: Panel(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Expanded(
                                  child: Text(
                                    delivery.id,
                                    style: Theme.of(
                                      context,
                                    ).textTheme.titleLarge,
                                  ),
                                ),
                                BadgeLabel(delivery.progressLabel),
                              ],
                            ),
                            const SizedBox(height: 10),
                            Text(
                              '${delivery.stops.length} pickup stops • 1 customer',
                              style: const TextStyle(color: Color(0xFF607099)),
                            ),
                            const Divider(height: 28),
                            for (final stop in delivery.stops)
                              ListTile(
                                contentPadding: EdgeInsets.zero,
                                leading: Icon(
                                  stop.pickedUp
                                      ? Icons.check_circle
                                      : Icons.storefront_outlined,
                                  color: stop.pickedUp ? Colors.green : blue,
                                ),
                                title: Text(stop.name),
                                subtitle: Text(stop.address),
                              ),
                            ListTile(
                              contentPadding: EdgeInsets.zero,
                              leading: const Icon(
                                Icons.location_on_outlined,
                                color: blue,
                              ),
                              title: Text(delivery.customer),
                              subtitle: Text(delivery.address),
                            ),
                            const SizedBox(height: 12),
                            FilledButton(
                              onPressed: online
                                  ? () => openDelivery(delivery)
                                  : null,
                              child: const Text('View delivery →'),
                            ),
                          ],
                        ),
                      ),
                    ),
                ],
                if (tab == 1) ...[
                  Text(
                    'Your deliveries',
                    style: Theme.of(context).textTheme.headlineMedium,
                  ),
                  const SizedBox(height: 18),
                  if (completed.isEmpty)
                    const Panel(
                      child: Column(
                        children: [
                          Icon(Icons.history, size: 48, color: blue),
                          SizedBox(height: 12),
                          Text('Your completed deliveries will appear here.'),
                        ],
                      ),
                    ),
                  for (final delivery in completed)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 14),
                      child: Panel(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              delivery.id,
                              style: Theme.of(context).textTheme.titleLarge,
                            ),
                            const SizedBox(height: 8),
                            const BadgeLabel('Delivered'),
                            const SizedBox(height: 12),
                            Text(delivery.customer),
                            Text(delivery.address),
                            const Divider(height: 28),
                            Text('COD collected: ₹${delivery.cod}'),
                            Text('Receipt: ${delivery.receipt}'),
                          ],
                        ),
                      ),
                    ),
                ],
                if (tab == 2) ...[
                  Text(
                    'Your account',
                    style: Theme.of(context).textTheme.headlineMedium,
                  ),
                  const SizedBox(height: 20),
                  const Panel(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        CircleAvatar(
                          radius: 30,
                          child: Icon(Icons.person, size: 34),
                        ),
                        SizedBox(height: 18),
                        Text(
                          'Arjun Kumar',
                          style: TextStyle(
                            fontSize: 22,
                            fontWeight: FontWeight.bold,
                            color: navy,
                          ),
                        ),
                        Text('Sample delivery partner'),
                        SizedBox(height: 20),
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: Icon(Icons.two_wheeler),
                          title: Text('Honda Activa'),
                          subtitle: Text('Demo vehicle'),
                        ),
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: Icon(Icons.verified_user_outlined),
                          title: Text('KYC preview'),
                          subtitle: Text(
                            'Live document submission and approval are not connected.',
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 20),
                  const Panel(
                    child: Text(
                      'This demo stores changes in memory. Restarting the app resets sample deliveries. Return to the sign-in screen to use your rider account and live assigned deliveries.',
                    ),
                  ),
                  const SizedBox(height: 20),
                  OutlinedButton(
                    onPressed: () => setState(() {
                      entered = false;
                      tab = 0;
                    }),
                    child: const Text('Exit demo'),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class DeliveryScreen extends StatefulWidget {
  const DeliveryScreen({super.key, required this.delivery});
  final Delivery delivery;
  @override
  State<DeliveryScreen> createState() => _DeliveryScreenState();
}

class _DeliveryScreenState extends State<DeliveryScreen> {
  final form = GlobalKey<FormState>();
  final receipt = TextEditingController();
  bool paymentCollected = false;
  @override
  void dispose() {
    receipt.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final delivery = widget.delivery;
    final stop = delivery.nextStop;
    return Scaffold(
      appBar: AppBar(title: Text('Order ${delivery.id}')),
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 640),
            child: ListView(
              padding: const EdgeInsets.all(20),
              children: [
                const BadgeLabel('DEMO DELIVERY'),
                const SizedBox(height: 18),
                Wrap(
                  spacing: 10,
                  runSpacing: 10,
                  children: [
                    for (var i = 0; i < delivery.stops.length; i++)
                      Chip(
                        avatar: Icon(
                          delivery.stops[i].pickedUp
                              ? Icons.check_circle
                              : Icons.storefront,
                          size: 18,
                          color: blue,
                        ),
                        label: Text('Pickup ${i + 1}'),
                      ),
                    const Chip(
                      avatar: Icon(Icons.location_on, size: 18),
                      label: Text('Delivery'),
                    ),
                  ],
                ),
                const SizedBox(height: 20),
                if (stop != null) ...[
                  Text(
                    'Collect your pickup',
                    style: Theme.of(context).textTheme.headlineMedium,
                  ),
                  const SizedBox(height: 8),
                  const Text('Check every item before confirming this stop.'),
                  const SizedBox(height: 20),
                  Panel(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Icon(
                          Icons.storefront_outlined,
                          size: 40,
                          color: blue,
                        ),
                        const SizedBox(height: 12),
                        Text(
                          stop.name,
                          style: Theme.of(context).textTheme.titleLarge,
                        ),
                        const SizedBox(height: 6),
                        SelectableText(stop.address),
                        const Divider(height: 32),
                        for (final item in stop.items)
                          ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: const Icon(
                              Icons.inventory_2_outlined,
                              color: blue,
                            ),
                            title: Text(item.name),
                            trailing: Text('× ${item.quantity}'),
                          ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 24),
                  FilledButton(
                    onPressed: () async {
                      final confirmed = await showDialog<bool>(
                        context: context,
                        builder: (context) => AlertDialog(
                          title: const Text('Confirm pickup?'),
                          content: Text(
                            'Have you collected all items from ${stop.name}?',
                          ),
                          actions: [
                            TextButton(
                              onPressed: () => Navigator.pop(context, false),
                              child: const Text('Check again'),
                            ),
                            FilledButton(
                              style: FilledButton.styleFrom(
                                minimumSize: const Size(0, 44),
                              ),
                              onPressed: () => Navigator.pop(context, true),
                              child: const Text('Collected'),
                            ),
                          ],
                        ),
                      );
                      if (confirmed == true && mounted) {
                        setState(() => delivery.confirmPickup());
                      }
                    },
                    child: const Text('Confirm pickup'),
                  ),
                ] else ...[
                  Text(
                    'Deliver with care',
                    style: Theme.of(context).textTheme.headlineMedium,
                  ),
                  const SizedBox(height: 20),
                  Panel(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Icon(
                          Icons.person_pin_circle_outlined,
                          size: 40,
                          color: blue,
                        ),
                        const SizedBox(height: 12),
                        Text(
                          delivery.customer,
                          style: Theme.of(context).textTheme.titleLarge,
                        ),
                        const SizedBox(height: 8),
                        SelectableText(delivery.address),
                        const Divider(height: 30),
                        for (final pickup in delivery.stops)
                          for (final item in pickup.items)
                            ListTile(
                              contentPadding: EdgeInsets.zero,
                              title: Text(item.name),
                              trailing: Text('× ${item.quantity}'),
                            ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 18),
                  Container(
                    padding: const EdgeInsets.all(22),
                    decoration: BoxDecoration(
                      color: navy,
                      borderRadius: BorderRadius.circular(18),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'Collect on delivery',
                          style: TextStyle(color: Colors.white70),
                        ),
                        Text(
                          '₹${delivery.cod}',
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 34,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 20),
                  Form(
                    key: form,
                    child: TextFormField(
                      controller: receipt,
                      maxLength: 80,
                      decoration: const InputDecoration(
                        labelText: 'Collection receipt reference',
                        hintText: 'Enter a receipt reference',
                      ),
                      validator: (value) => (value ?? '').trim().isEmpty
                          ? 'Enter the collection receipt reference'
                          : null,
                    ),
                  ),
                  CheckboxListTile(
                    contentPadding: EdgeInsets.zero,
                    value: paymentCollected,
                    onChanged: (value) =>
                        setState(() => paymentCollected = value ?? false),
                    title: const Text(
                      'Payment collected and items handed over',
                    ),
                  ),
                  const SizedBox(height: 12),
                  FilledButton(
                    onPressed: paymentCollected
                        ? () {
                            if (!form.currentState!.validate()) return;
                            delivery.complete(
                              receipt.text,
                              paymentCollected: paymentCollected,
                            );
                            Navigator.pop(context);
                          }
                        : null,
                    child: const Text('Confirm delivery & collection'),
                  ),
                ],
                const SizedBox(height: 20),
                const Text(
                  'Sample order • No real order or payment will be updated.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Color(0xFF607099)),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class Panel extends StatelessWidget {
  const Panel({super.key, required this.child});
  final Widget child;
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(18),
    decoration: BoxDecoration(
      color: Colors.white,
      border: Border.all(color: const Color(0xFFE1E8F5)),
      borderRadius: BorderRadius.circular(20),
    ),
    child: Material(color: Colors.transparent, child: child),
  );
}

class BadgeLabel extends StatelessWidget {
  const BadgeLabel(this.label, {super.key});
  final String label;
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
    decoration: BoxDecoration(
      color: const Color(0xFFE8F0FF),
      borderRadius: BorderRadius.circular(20),
    ),
    child: Text(
      label,
      style: const TextStyle(
        color: blue,
        fontSize: 12,
        fontWeight: FontWeight.bold,
      ),
    ),
  );
}

class Metric extends StatelessWidget {
  const Metric({
    super.key,
    required this.value,
    required this.label,
    required this.icon,
  });
  final String value;
  final String label;
  final IconData icon;
  @override
  Widget build(BuildContext context) => Panel(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, color: blue),
        const SizedBox(height: 10),
        Text(value, style: Theme.of(context).textTheme.headlineMedium),
        Text(label, style: const TextStyle(color: Color(0xFF607099))),
      ],
    ),
  );
}
