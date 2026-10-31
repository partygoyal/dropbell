import 'package:flutter/material.dart';
import 'customer_api.dart';
import 'ui.dart';

class LocationSelector extends StatefulWidget {
  const LocationSelector({
    super.key,
    required this.api,
    required this.onChanged,
    this.localityId,
    this.enabled = true,
  });
  final CustomerApi api;
  final Object? localityId;
  final bool enabled;
  final void Function(Map<String, dynamic>) onChanged;
  @override
  State<LocationSelector> createState() => _LocationSelectorState();
}

class _LocationSelectorState extends State<LocationSelector> {
  List<dynamic> states = [];
  String? stateId, cityId, localityId, error;
  bool loading = true;
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    try {
      final response = await widget.api.request('GET', 'locations');
      if (!mounted) return;
      states = response['data'] as List;
      for (final state in states) {
        for (final city in state['cities']) {
          for (final area in city['localities']) {
            if (area['id'].toString() == widget.localityId?.toString()) {
              stateId = state['id'].toString();
              cityId = city['id'].toString();
              localityId = area['id'].toString();
            }
          }
        }
      }
    } catch (e) {
      error = friendlyError(e);
    }
    if (mounted) setState(() => loading = false);
  }

  @override
  Widget build(BuildContext context) {
    if (loading) {
      return const Padding(
        padding: EdgeInsets.all(16),
        child: LinearProgressIndicator(),
      );
    }
    if (error != null) {
      return ErrorBox(
        error!,
        retry: () {
          setState(() {
            loading = true;
            error = null;
          });
          load();
        },
      );
    }
    if (states.isEmpty) {
      return const ShopCard(
        child: Text(
          'We are not delivering in any locations yet. Please check again later.',
        ),
      );
    }
    final state = states
        .where((s) => s['id'].toString() == stateId)
        .firstOrNull;
    final cities = (state?['cities'] as List?) ?? [];
    final city = cities.where((c) => c['id'].toString() == cityId).firstOrNull;
    final localities = (city?['localities'] as List?) ?? [];
    Widget dropdown(
      String label,
      List<dynamic> items,
      String? value,
      void Function(String?) change,
    ) => Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: DropdownButtonFormField<String>(
        key: ValueKey('$label:$value:${items.length}'),
        initialValue: value,
        isExpanded: true,
        decoration: InputDecoration(labelText: label),
        items: [
          for (final item in items)
            DropdownMenuItem(
              value: item['id'].toString(),
              child: Text(item['name']),
            ),
        ],
        onChanged: widget.enabled && items.isNotEmpty ? change : null,
      ),
    );
    return Column(
      children: [
        dropdown('State', states, stateId, (id) {
          setState(() {
            stateId = id;
            cityId = null;
            localityId = null;
          });
          widget.onChanged({});
        }),
        dropdown('City', cities, cityId, (id) {
          setState(() {
            cityId = id;
            localityId = null;
          });
          widget.onChanged({});
        }),
        dropdown('Locality / Area', localities, localityId, (id) {
          setState(() => localityId = id);
          final locality = localities.firstWhere(
            (l) => l['id'].toString() == id,
          );
          widget.onChanged({
            'state_id': int.parse(stateId!),
            'city_id': int.parse(cityId!),
            'locality_id': int.parse(id!),
            'state': state['name'],
            'city': city['name'],
            'locality': locality['name'],
            'pin': locality['postal_code'],
          });
        }),
      ],
    );
  }
}
