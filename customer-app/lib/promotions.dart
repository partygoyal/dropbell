import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'customer_api.dart';
import 'ui.dart';

class PromotionCarousel extends StatefulWidget {
  const PromotionCarousel({
    super.key,
    required this.api,
    this.section = 'groceries',
  });
  final CustomerApi api;
  final String section;
  @override
  State<PromotionCarousel> createState() => _PromotionCarouselState();
}

class _PromotionCarouselState extends State<PromotionCarousel>
    with WidgetsBindingObserver {
  final controller = PageController();
  List<Map<String, dynamic>> offers = [];
  Timer? rotate, reload;
  int index = 0;
  bool active = true, paused = false, dragging = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    load();
    reload = Timer.periodic(const Duration(minutes: 1), (_) {
      if (active) load();
    });
    rotate = Timer.periodic(const Duration(seconds: 6), (_) {
      if (!mounted ||
          !active ||
          paused ||
          dragging ||
          offers.length < 2 ||
          !controller.hasClients ||
          !(ModalRoute.of(context)?.isCurrent ?? true)) {
        return;
      }
      final media = MediaQuery.of(context);
      if (media.disableAnimations || media.accessibleNavigation) return;
      controller.animateToPage(
        (index + 1) % offers.length,
        duration: const Duration(milliseconds: 450),
        curve: Curves.easeInOut,
      );
    });
  }

  Future<void> load() async {
    try {
      final result = await widget.api.request('GET', 'promotions');
      final rows = (result['data'] as List)
          .whereType<Map>()
          .where((row) => row['title'] is String && row['id'] is int)
          .where(
            (row) =>
                row['banner_section'] == 'all' ||
                (row['banner_section'] ?? 'groceries') == widget.section,
          )
          .map((row) => Map<String, dynamic>.from(row))
          .toList();
      if (!mounted) return;
      setState(() {
        offers = rows;
        if (index >= offers.length) index = 0;
      });
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted &&
            controller.hasClients &&
            (controller.page?.round() ?? 0) >= offers.length) {
          controller.jumpToPage(index);
        }
      });
    } catch (_) {
      // Offers are optional and must not interrupt catalogue loading.
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    active = state == AppLifecycleState.resumed;
    if (active) load();
  }

  @override
  void dispose() {
    rotate?.cancel();
    reload?.cancel();
    controller.dispose();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (offers.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        children: [
          SizedBox(
            height: 300,
            child: NotificationListener<ScrollNotification>(
              onNotification: (event) {
                if (event is ScrollStartNotification &&
                    event.dragDetails != null) {
                  dragging = true;
                }
                if (event is ScrollEndNotification) dragging = false;
                return false;
              },
              child: PageView.builder(
                controller: controller,
                itemCount: offers.length,
                onPageChanged: (value) => setState(() => index = value),
                itemBuilder: (context, number) {
                  final offer = offers[number];
                  return _PromotionCreativeCard(
                    offer: offer,
                    number: number,
                    total: offers.length,
                    onShop: () {
                      final position = Scrollable.maybeOf(
                        this.context,
                      )?.position;
                      if (position != null) {
                        position.animateTo(
                          (position.pixels + 300).clamp(
                            0.0,
                            position.maxScrollExtent,
                          ),
                          duration: const Duration(milliseconds: 400),
                          curve: Curves.easeInOut,
                        );
                      }
                    },
                  );
                },
              ),
            ),
          ),
          if (offers.length > 1)
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                IconButton(
                  tooltip: 'Previous offer',
                  onPressed: () => controller.animateToPage(
                    (index + offers.length - 1) % offers.length,
                    duration: const Duration(milliseconds: 300),
                    curve: Curves.easeInOut,
                  ),
                  icon: const Icon(Icons.chevron_left),
                ),
                Text(
                  '${index + 1} / ${offers.length}',
                  style: const TextStyle(fontSize: 12),
                ),
                IconButton(
                  tooltip: 'Next offer',
                  onPressed: () => controller.animateToPage(
                    (index + 1) % offers.length,
                    duration: const Duration(milliseconds: 300),
                    curve: Curves.easeInOut,
                  ),
                  icon: const Icon(Icons.chevron_right),
                ),
                IconButton(
                  tooltip: paused
                      ? 'Resume automatic offers'
                      : 'Pause automatic offers',
                  onPressed: () => setState(() => paused = !paused),
                  icon: Icon(paused ? Icons.play_arrow : Icons.pause),
                ),
              ],
            ),
        ],
      ),
    );
  }
}

class _PromotionCreativeCard extends StatelessWidget {
  const _PromotionCreativeCard({
    required this.offer,
    required this.number,
    required this.total,
    required this.onShop,
  });
  final Map<String, dynamic> offer;
  final int number, total;
  final VoidCallback onShop;

  @override
  Widget build(BuildContext context) {
    final food = offer['banner_section'] == 'food';
    final theme = number % 3;
    final dark = theme == 0;
    final ink = dark ? Colors.white : navy;
    final accent = dark ? const Color(0xFFFFF06B) : blue;
    final detail = offer['offer'] is Map
        ? Map<String, dynamic>.from(offer['offer'])
        : null;
    final code = offer['code'] as String?;
    final colors = [
      [const Color(0xFF063AA7), const Color(0xFF1473FF)],
      [const Color(0xFFFFF3B7), const Color(0xFFFFDE67)],
      [const Color(0xFFE7FFF4), const Color(0xFFA8EBD1)],
    ][theme];
    return Semantics(
      label: 'Offer ${number + 1} of $total',
      child: Container(
        margin: const EdgeInsets.symmetric(horizontal: 2),
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(16),
          gradient: LinearGradient(colors: colors),
        ),
        child: Stack(
          children: [
            Positioned(
              right: -80,
              top: -35,
              child: Container(
                width: 270,
                height: 270,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(
                    color: Colors.white.withValues(alpha: .15),
                    width: 25,
                  ),
                ),
              ),
            ),
            if (offer['image_url'] is String)
              Positioned(
                right: -8,
                top: 42,
                bottom: 66,
                width: 157,
                child: Image.network(
                  offer['image_url'],
                  fit: BoxFit.contain,
                  excludeFromSemantics: true,
                  errorBuilder: (_, _, _) => const SizedBox.shrink(),
                ),
              ),
            Padding(
              padding: const EdgeInsets.all(18),
              child: SingleChildScrollView(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      (food
                          ? [
                              'BIG FLAVOUR. LITTLE PRICES.',
                              'A FEAST FROM YOUR NEIGHBOURHOOD.',
                              'SOMETHING SWEET. SOMETHING SPECIAL.',
                            ]
                          : [
                              'FRESH FINDS. BIG SAVINGS.',
                              'YOUR EVERYDAY, FOR LESS.',
                              'A BAG FULL OF GOOD DEALS.',
                            ])[theme],
                      style: TextStyle(
                        fontSize: 9,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 1,
                        color: ink,
                      ),
                    ),
                    const SizedBox(height: 12),
                    if (detail != null) ...[
                      Text(
                        detail['kind'] == 'fixed' ? 'FLAT' : 'SAVE',
                        style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w800,
                          letterSpacing: 2,
                          color: ink,
                        ),
                      ),
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.baseline,
                        textBaseline: TextBaseline.alphabetic,
                        children: [
                          Text(
                            detail['value'],
                            style: TextStyle(
                              fontSize: 62,
                              height: 1,
                              fontWeight: FontWeight.w900,
                              letterSpacing: -3,
                              color: accent,
                            ),
                          ),
                          const SizedBox(width: 8),
                          Text(
                            'OFF',
                            style: TextStyle(
                              fontSize: 27,
                              fontWeight: FontWeight.w900,
                              color: accent,
                            ),
                          ),
                        ],
                      ),
                    ],
                    const SizedBox(height: 9),
                    SizedBox(
                      width: 185,
                      child: Text(
                        offer['title'],
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                          color: ink,
                        ),
                      ),
                    ),
                    if (detail != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 6, bottom: 12),
                        child: SizedBox(
                          width: 180,
                          child: Text(
                            [
                              detail['minimum'],
                              detail['maximum'],
                            ].whereType<String>().join(' · '),
                            style: TextStyle(
                              fontSize: 10,
                              height: 1.4,
                              color: ink,
                            ),
                          ),
                        ),
                      ),
                    Wrap(
                      spacing: 10,
                      runSpacing: 5,
                      children: [
                        FilledButton(
                          onPressed: onShop,
                          style: FilledButton.styleFrom(
                            backgroundColor: dark ? accent : navy,
                            foregroundColor: dark ? navy : Colors.white,
                            padding: const EdgeInsets.symmetric(horizontal: 16),
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(7),
                            ),
                          ),
                          child: const Text('Shop now ↗'),
                        ),
                        if (code != null)
                          OutlinedButton(
                            onPressed: () async {
                              try {
                                await Clipboard.setData(
                                  ClipboardData(text: code),
                                );
                                if (context.mounted) {
                                  ScaffoldMessenger.of(context).showSnackBar(
                                    SnackBar(
                                      content: Text(
                                        '$code copied. Apply it at checkout.',
                                      ),
                                    ),
                                  );
                                }
                              } catch (_) {}
                            },
                            style: OutlinedButton.styleFrom(
                              foregroundColor: ink,
                              side: BorderSide(
                                color: ink.withValues(alpha: .5),
                              ),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(7),
                              ),
                            ),
                            child: Text(
                              'CODE $code',
                              style: const TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.w800,
                                letterSpacing: .5,
                              ),
                            ),
                          ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
