import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'customer_api.dart';

const navy = Color(0xFF15324D);
const blue = Color(0xFF0064C8);
const muted = Color(0xFF718595);
String friendlyError(Object error) => error is ApiError
    ? error.message
    : 'Something went wrong while saving your changes. Please try again.';
void notifyError(BuildContext context, Object error) => ScaffoldMessenger.of(
  context,
).showSnackBar(SnackBar(content: Text(friendlyError(error))));

class ShopCard extends StatelessWidget {
  const ShopCard({super.key, required this.child});
  final Widget child;
  @override
  Widget build(BuildContext context) => Card(
    color: Colors.white,
    elevation: 0,
    margin: const EdgeInsets.symmetric(vertical: 8),
    shape: RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(12),
      side: const BorderSide(color: Color(0xFFE7EEF3)),
    ),
    child: Padding(padding: const EdgeInsets.all(14), child: child),
  );
}

class ErrorBox extends StatelessWidget {
  const ErrorBox(this.message, {super.key, this.retry});
  final String message;
  final VoidCallback? retry;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          message,
          style: TextStyle(
            fontFamily: 'Roboto',
            color: Theme.of(context).colorScheme.error,
          ),
        ),
        if (retry != null)
          TextButton(onPressed: retry, child: const Text('Try again')),
      ],
    ),
  );
}

class ShopBody extends StatelessWidget {
  const ShopBody({super.key, required this.children, this.refresh});
  final List<Widget> children;
  final Future<void> Function()? refresh;
  @override
  Widget build(BuildContext context) {
    final list = ListView(
      physics: const CustomerScrollPhysics(
        parent: AlwaysScrollableScrollPhysics(),
      ),
      padding: const EdgeInsets.all(16),
      children: children,
    );
    return SafeArea(
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 720),
          child: refresh == null
              ? list
              : RefreshIndicator(onRefresh: refresh!, child: list),
        ),
      ),
    );
  }
}

class ProductImage extends StatelessWidget {
  const ProductImage(this.product, {super.key, this.size = 80});
  final Product product;
  final double size;
  @override
  Widget build(BuildContext context) {
    Widget fallback() => Semantics(
      label: '${product.category} illustration',
      child: CategoryPicture(categoryPicture(product.category), size: size),
    );
    return ClipRRect(
      borderRadius: BorderRadius.circular(14),
      child: SizedBox(
        width: size,
        height: size,
        child: product.image == null
            ? fallback()
            : Image.network(
                product.image!,
                fit: BoxFit.contain,
                errorBuilder: (_, _, _) => fallback(),
              ),
      ),
    );
  }
}

class BillView extends StatelessWidget {
  const BillView(this.bill, {super.key});
  final Map<String, dynamic> bill;
  @override
  Widget build(BuildContext context) => ShopCard(
    child: Column(
      children: [
        const Align(
          alignment: Alignment.centerLeft,
          child: SectionTitle('Order Summary'),
        ),
        for (final entry in {
          'Items total': bill['subtotal'],
          'Savings': -(bill['discount'] as int),
          'Delivery': bill['delivery'],
          'Total': bill['total'],
        }.entries)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    entry.key,
                    style: TextStyle(
                      fontFamily: 'Roboto',
                      fontWeight: entry.key == 'Total'
                          ? FontWeight.bold
                          : FontWeight.normal,
                    ),
                  ),
                ),
                Text(
                  rupees(entry.value as int),
                  style: TextStyle(
                    fontFamily: 'Roboto',
                    fontWeight: entry.key == 'Total'
                        ? FontWeight.bold
                        : FontWeight.normal,
                  ),
                ),
              ],
            ),
          ),
      ],
    ),
  );
}

class DeliveryBreakdown extends StatelessWidget {
  const DeliveryBreakdown(this.pricing, {super.key});
  final Map<String, dynamic> pricing;
  @override
  Widget build(BuildContext context) {
    if (!['per_km', 'locality_per_km'].contains(pricing['mode'])) {
      return const SizedBox.shrink();
    }
    return ShopCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            pricing['mode'] == 'locality_per_km'
                ? 'Locality delivery charge'
                : 'Road-distance delivery',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 8),
          Text(
            '${((pricing['distance_meters'] as num) / 1000).toStringAsFixed(3)} km × ${rupees((pricing['rate_paise_per_km'] as num).toInt())}/km',
          ),
          Text(
            pricing['mode'] == 'locality_per_km'
                ? 'Admin-set distance for ${pricing['zone_name'] ?? pricing['name'] ?? 'your locality'}'
                : 'Hub to your delivery pin · ${pricing['zone_name'] ?? pricing['name'] ?? ''}',
          ),
          if (pricing['fee_paise'] != null)
            Text(
              'Delivery charge: ${rupees((pricing['fee_paise'] as num).toInt())}',
            ),
        ],
      ),
    );
  }
}

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.title, {super.key});
  final String title;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: Text(title, style: Theme.of(context).textTheme.titleLarge),
  );
}

int categoryPicture(String name) {
  final text = name.toLowerCase();
  if (text.contains('fruit') ||
      text.contains('vegetable') ||
      text.contains('fresh')) {
    return 0;
  }
  if (text.contains('dairy') ||
      text.contains('bakery') ||
      text.contains('milk')) {
    return 1;
  }
  if (text.contains('oil') || text.contains('spice')) return 3;
  if (text.contains('snack')) return 4;
  if (text.contains('sweet') || text.contains('biscuit')) return 5;
  if (text.contains('breakfast')) return 6;
  if (text.contains('instant')) return 7;
  if (text.contains('sauce')) return 8;
  if (text.contains('beverage') || text.contains('drink')) return 9;
  if (text.contains('frozen')) return 10;
  if (text.contains('personal')) return 12;
  if (text.contains('household') || text.contains('clean')) return 13;
  if (text.contains('baby')) return 14;
  if (text.contains('pet')) return 15;
  return 2;
}

class CategoryPicture extends StatelessWidget {
  const CategoryPicture(this.index, {super.key, required this.size});
  final int index;
  final double size;
  @override
  Widget build(BuildContext context) => SizedBox(
    width: size,
    height: size,
    child: ClipRRect(
      borderRadius: BorderRadius.circular(12),
      child: OverflowBox(
        alignment: Alignment(
          -1 + (index % 4) * 2 / 3,
          -1 + (index ~/ 4) * 2 / 3,
        ),
        maxWidth: size * 4,
        minWidth: size * 4,
        maxHeight: size * 4,
        minHeight: size * 4,
        child: Image.asset(
          'assets/category-photography.png',
          width: size * 4,
          height: size * 4,
          fit: BoxFit.fill,
        ),
      ),
    ),
  );
}

class CategoryTile extends StatelessWidget {
  const CategoryTile({
    super.key,
    required this.name,
    required this.onTap,
    this.large = false,
    this.imageUrl,
  });
  final String name;
  final VoidCallback onTap;
  final bool large;
  final String? imageUrl;
  @override
  Widget build(BuildContext context) => Material(
    color: large ? Colors.white : Colors.transparent,
    shape: RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(12),
      side: BorderSide(
        color: large ? const Color(0xFFE7EEF3) : Colors.transparent,
      ),
    ),
    child: InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Padding(
        padding: EdgeInsets.all(large ? 12 : 0),
        child: Column(
          children: [
            LayoutBuilder(
              builder: (context, constraints) => imageUrl != null
                  ? SizedBox(
                      height: large ? 104 : 64,
                      width: large ? 104 : 64,
                      child: Image.network(
                        imageUrl!,
                        fit: BoxFit.contain,
                        errorBuilder: (_, _, _) =>
                            const Icon(Icons.restaurant, color: blue),
                      ),
                    )
                  : CategoryPicture(
                      categoryPicture(name),
                      size: constraints.maxWidth
                          .clamp(44, large ? 104 : 64)
                          .toDouble(),
                    ),
            ),
            const SizedBox(height: 8),
            Text(
              name,
              textAlign: TextAlign.center,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontFamily: 'Roboto',
                fontSize: large ? 13 : 11,
                fontWeight: FontWeight.w600,
                color: navy,
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class GroceryBanner extends StatelessWidget {
  const GroceryBanner({super.key, required this.onShop});
  final VoidCallback onShop;
  @override
  Widget build(BuildContext context) => Container(
    clipBehavior: Clip.antiAlias,
    decoration: BoxDecoration(
      gradient: const LinearGradient(
        colors: [Color(0xFF087A87), Color(0xFF085C52)],
      ),
      borderRadius: BorderRadius.circular(14),
    ),
    child: LayoutBuilder(
      builder: (context, constraints) => Row(
        children: [
          Expanded(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 20, 0, 20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Fresh Grocery\nHealthy Life',
                    style: TextStyle(
                      fontFamily: 'Roboto',
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                      fontSize: 22,
                      height: 1.1,
                    ),
                  ),
                  const SizedBox(height: 8),
                  const Text(
                    'Sab Kuch, Ab Aapke Ghar Tak',
                    style: TextStyle(
                      fontFamily: 'Roboto',
                      color: Colors.white,
                      fontSize: 10,
                    ),
                  ),
                  const SizedBox(height: 12),
                  SizedBox(
                    height: 32,
                    child: FilledButton(
                      onPressed: onShop,
                      style: FilledButton.styleFrom(
                        backgroundColor: Colors.white,
                        foregroundColor: const Color(0xFF075E67),
                        minimumSize: const Size(0, 32),
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                      ),
                      child: const Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            'Shop Now',
                            style: TextStyle(
                              fontFamily: 'Roboto',
                              fontSize: 11,
                            ),
                          ),
                          SizedBox(width: 8),
                          Icon(Icons.arrow_forward, size: 14),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(12),
            child: CategoryPicture(
              0,
              size: (constraints.maxWidth * .36).clamp(72, 170).toDouble(),
            ),
          ),
        ],
      ),
    ),
  );
}

class BrandLoading extends StatelessWidget {
  const BrandLoading({super.key});
  @override
  Widget build(BuildContext context) => AnnotatedRegion<SystemUiOverlayStyle>(
    value: SystemUiOverlayStyle.light,
    child: LayoutBuilder(
      builder: (context, constraints) => Stack(
        fit: StackFit.expand,
        children: [
          Image.asset('assets/dehradun-splash.png', fit: BoxFit.cover),
          const DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [
                  Color(0x220064C8),
                  Color(0x000064C8),
                  Color(0xEE043875),
                ],
                stops: [0, .6, 1],
              ),
            ),
          ),
          SafeArea(
            child: Column(
              children: [
                SizedBox(height: constraints.maxHeight * .09),
                DropbellMark(size: (constraints.maxHeight * .2).clamp(90, 160)),
                const SizedBox(height: 12),
                const Text(
                  'Dropbell',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 44,
                    fontWeight: FontWeight.w800,
                    letterSpacing: -1.5,
                    height: 1.1,
                  ),
                ),
                const SizedBox(height: 14),
                const Text(
                  'Sab Kuch, Ab Aapke Ghar Tak',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 16,
                    fontWeight: FontWeight.w500,
                  ),
                ),
                const SizedBox(height: 24),
                const Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(Icons.location_on, color: Colors.white, size: 18),
                    SizedBox(width: 5),
                    Text(
                      'Dehradun',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 15,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
                const Spacer(),
                const SizedBox(
                  width: 62,
                  child: LinearProgressIndicator(
                    color: Colors.white,
                    backgroundColor: Color(0x44FFFFFF),
                    minHeight: 3,
                  ),
                ),
                const SizedBox(height: 18),
                const Text(
                  'Your everyday essentials, at your doorstep.',
                  style: TextStyle(color: Colors.white, fontSize: 11),
                ),
                const SizedBox(height: 32),
              ],
            ),
          ),
        ],
      ),
    ),
  );
}

class CustomerScrollPhysics extends ClampingScrollPhysics {
  const CustomerScrollPhysics({super.parent});

  @override
  CustomerScrollPhysics applyTo(ScrollPhysics? ancestor) =>
      CustomerScrollPhysics(parent: buildParent(ancestor));

  @override
  double get maxFlingVelocity => 2200;

  @override
  double carriedMomentum(double existingVelocity) => 0;

  @override
  double applyPhysicsToUserOffset(ScrollMetrics position, double offset) =>
      super.applyPhysicsToUserOffset(position, offset) * .85;
}

class CustomerScrollBehavior extends MaterialScrollBehavior {
  const CustomerScrollBehavior();

  @override
  ScrollPhysics getScrollPhysics(BuildContext context) =>
      const CustomerScrollPhysics(parent: AlwaysScrollableScrollPhysics());
}

class DropbellLogo extends StatelessWidget {
  const DropbellLogo({super.key, this.width = 180, this.inverse = false});
  final double width;
  final bool inverse;

  @override
  Widget build(BuildContext context) {
    final logo = Image.asset(
      'assets/dropbell-logo.png',
      fit: BoxFit.contain,
      semanticLabel: 'Dropbell',
    );
    return Container(
      width: width,
      padding: inverse
          ? EdgeInsets.zero
          : const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: inverse ? Colors.transparent : Colors.white,
        borderRadius: BorderRadius.circular(10),
      ),
      child: inverse
          ? ColorFiltered(
              // Convert blue ink to white, retaining the transparent bell cutout.
              colorFilter: const ColorFilter.matrix([
                0,
                0,
                0,
                0,
                255,
                0,
                0,
                0,
                0,
                255,
                0,
                0,
                0,
                0,
                255,
                -1,
                0,
                0,
                1,
                0,
              ]),
              child: logo,
            )
          : logo,
    );
  }
}

class DropbellMark extends StatelessWidget {
  const DropbellMark({super.key, required this.size});
  final double size;
  @override
  Widget build(BuildContext context) => Semantics(
    label: 'Dropbell',
    child: SizedBox.square(
      dimension: size,
      child: ClipRect(
        child: OverflowBox(
          alignment: Alignment.centerLeft,
          minWidth: size * 3.463,
          maxWidth: size * 3.463,
          maxHeight: size * 1.4,
          child: DropbellLogo(width: size * 3.463, inverse: true),
        ),
      ),
    ),
  );
}
