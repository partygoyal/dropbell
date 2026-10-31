class DeliveryItem {
  const DeliveryItem(this.name, this.quantity);
  final String name;
  final int quantity;
}

class PickupStop {
  PickupStop({required this.name, required this.address, required this.items});
  final String name;
  final String address;
  final List<DeliveryItem> items;
  bool _pickedUp = false;
  bool get pickedUp => _pickedUp;
}

class Delivery {
  Delivery({
    required this.id,
    required this.customer,
    required this.address,
    required this.cod,
    required this.stops,
  });
  final String id;
  final String customer;
  final String address;
  final int cod;
  final List<PickupStop> stops;
  bool _delivered = false;
  String? _receipt;
  bool get delivered => _delivered;
  String? get receipt => _receipt;
  PickupStop? get nextStop {
    for (final stop in stops) {
      if (!stop.pickedUp) return stop;
    }
    return null;
  }

  String get progressLabel => delivered
      ? 'Delivered'
      : nextStop == null
      ? 'Out for delivery'
      : stops.any((s) => s.pickedUp)
      ? 'In progress'
      : 'Assigned';
  void confirmPickup() {
    final stop = nextStop;
    if (delivered || stop == null) throw StateError('No pending pickup');
    stop._pickedUp = true;
  }

  void complete(String reference, {required bool paymentCollected}) {
    if (delivered || stops.isEmpty || nextStop != null) {
      throw StateError('All pickups must be completed first');
    }
    if (!paymentCollected ||
        reference.trim().isEmpty ||
        reference.trim().length > 80) {
      throw StateError('Payment confirmation and a valid receipt are required');
    }
    _receipt = reference.trim();
    _delivered = true;
  }
}

List<Delivery> demoDeliveries() => [
  Delivery(
    id: 'DB2042',
    customer: 'Priya Sharma',
    address: '12 Garden Road, Dehradun 248001',
    cod: 232,
    stops: [
      PickupStop(
        name: 'Daily Dairy',
        address: 'Market Road, Dehradun 248001',
        items: const [
          DeliveryItem('Fresh paneer · 200 g', 1),
          DeliveryItem('Cheese slices · 100 g', 1),
        ],
      ),
      PickupStop(
        name: 'Green Basket Store',
        address: 'Rajpur Road, Dehradun 248001',
        items: const [DeliveryItem('Apples · 1 kg', 1)],
      ),
    ],
  ),
  Delivery(
    id: 'DB2043',
    customer: 'Rohan Mehta',
    address: '24 Canal Road, Dehradun 248001',
    cod: 120,
    stops: [
      PickupStop(
        name: 'Daily Dairy',
        address: 'Market Road, Dehradun 248001',
        items: const [DeliveryItem('Milk · 1 L', 2)],
      ),
    ],
  ),
];
