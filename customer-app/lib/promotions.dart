import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'customer_api.dart';
import 'ui.dart';

class PromotionCarousel extends StatefulWidget {
  const PromotionCarousel({super.key, required this.api});
  final CustomerApi api;
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
            height: 210,
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
                  final code = offer['code'] as String?;
                  return Semantics(
                    label: 'Offer ${number + 1} of ${offers.length}',
                    child: Container(
                      margin: const EdgeInsets.symmetric(horizontal: 2),
                      padding: const EdgeInsets.all(18),
                      decoration: BoxDecoration(
                        color: const Color(0xFFEAF2FF),
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: const Color(0xFFD2E1F7)),
                      ),
                      child: Row(
                        children: [
                          Expanded(
                            child: SingleChildScrollView(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const Text(
                                    'CURRENT OFFERS',
                                    style: TextStyle(
                                      fontSize: 10,
                                      fontWeight: FontWeight.w700,
                                      color: blue,
                                      letterSpacing: 1,
                                    ),
                                  ),
                                  const SizedBox(height: 8),
                                  Text(
                                    offer['title'],
                                    style: const TextStyle(
                                      fontSize: 20,
                                      fontWeight: FontWeight.w800,
                                      color: navy,
                                    ),
                                  ),
                                  if ((offer['description'] as String?)
                                          ?.isNotEmpty ==
                                      true)
                                    Padding(
                                      padding: const EdgeInsets.only(top: 8),
                                      child: Text(
                                        offer['description'],
                                        style: const TextStyle(fontSize: 12),
                                      ),
                                    ),
                                  if (code != null)
                                    Padding(
                                      padding: const EdgeInsets.only(top: 10),
                                      child: OutlinedButton(
                                        onPressed: () async {
                                          try {
                                            await Clipboard.setData(
                                              ClipboardData(text: code),
                                            );
                                            if (context.mounted) {
                                              ScaffoldMessenger.of(
                                                context,
                                              ).showSnackBar(
                                                SnackBar(
                                                  content: Text(
                                                    '$code copied. Apply it at checkout.',
                                                  ),
                                                ),
                                              );
                                            }
                                          } catch (_) {}
                                        },
                                        child: Text('Use $code · Copy'),
                                      ),
                                    ),
                                ],
                              ),
                            ),
                          ),
                          if (offer['image_url'] is String)
                            Padding(
                              padding: const EdgeInsets.only(left: 12),
                              child: ClipRRect(
                                borderRadius: BorderRadius.circular(10),
                                child: Image.network(
                                  offer['image_url'],
                                  width: 85,
                                  height: 140,
                                  fit: BoxFit.cover,
                                  excludeFromSemantics: true,
                                  errorBuilder: (_, _, _) =>
                                      const SizedBox.shrink(),
                                ),
                              ),
                            ),
                        ],
                      ),
                    ),
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
