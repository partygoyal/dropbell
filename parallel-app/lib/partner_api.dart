import 'dart:async';
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

class ApiFailure implements Exception {
  const ApiFailure(this.message, [this.status = 0]);
  final String message;
  final int status;
  @override
  String toString() => message;
}

String partnerError(Object error) => error is ApiFailure
    ? error.message
    : 'Something went wrong. Refresh and review the latest details before trying again.';

abstract class TokenStore {
  Future<String?> read();
  Future<void> write(String token);
  Future<void> clear();
}

class SecureTokenStore implements TokenStore {
  const SecureTokenStore(this.key);
  final String key;
  static const storage = FlutterSecureStorage();
  @override
  Future<String?> read() => storage.read(key: key);
  @override
  Future<void> write(String token) => storage.write(key: key, value: token);
  @override
  Future<void> clear() => storage.delete(key: key);
}

class PartnerApi {
  PartnerApi({
    required this.baseUrl,
    http.Client? client,
    TokenStore? store,
    this.timeout = const Duration(seconds: 20),
  }) : client = client ?? http.Client(),
       store = store ?? SecureTokenStore('partner_token:$baseUrl');

  factory PartnerApi.configured() {
    const configured = String.fromEnvironment('API_BASE_URL');
    final local = !kIsWeb && defaultTargetPlatform == TargetPlatform.android
        ? 'http://10.0.2.2:8000/api'
        : 'http://127.0.0.1:8000/api';
    return PartnerApi(
      baseUrl: configured.isEmpty
          ? (kReleaseMode ? 'https://dropbell.in/backend-api/api' : local)
          : configured,
    );
  }

  final String baseUrl;
  final Duration timeout;
  final http.Client client;
  final TokenStore store;
  String? _token;
  bool get signedIn => _token != null;

  Future<void> restore() async {
    _token = await store.read();
  }

  void close() => client.close();

  Future<Map<String, dynamic>> request(
    String method,
    String path, [
    Map<String, dynamic>? body,
  ]) async {
    final base = Uri.tryParse(baseUrl);
    if (base == null ||
        !base.hasAuthority ||
        !['https', 'http'].contains(base.scheme) ||
        (kReleaseMode && base.scheme != 'https')) {
      throw const ApiFailure(
        'The server connection is not configured. Contact your admin.',
      );
    }
    try {
      final request = http.Request(
        method,
        Uri.parse('${baseUrl.replaceFirst(RegExp(r'/$'), '')}/partner/$path'),
      );
      request.headers.addAll({
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (_token != null) 'Authorization': 'Bearer $_token',
      });
      if (body != null) request.body = jsonEncode(body);
      final response = await (() async {
        return http.Response.fromStream(await client.send(request));
      })().timeout(timeout);
      if (response.statusCode == 401) {
        try {
          await forgetSession();
        } catch (_) {
          throw const ApiFailure(
            'Your session expired. Please sign in again.',
            401,
          );
        }
        throw const ApiFailure(
          'Your session expired. Please sign in again.',
          401,
        );
      }
      if (response.statusCode >= 500) {
        throw ApiFailure(
          'Dropbell is temporarily unavailable. Please try again shortly.',
          response.statusCode,
        );
      }
      if (response.statusCode == 429) {
        throw const ApiFailure(
          'Too many requests. Please wait a moment before trying again.',
          429,
        );
      }
      Map<String, dynamic> data;
      try {
        data = jsonDecode(response.body) as Map<String, dynamic>;
      } catch (_) {
        throw ApiFailure(
          'The server returned an unexpected response. Try refreshing.',
          response.statusCode,
        );
      }
      if (response.statusCode >= 400) {
        final errors = data['errors'];
        String? message;
        if (errors is Map) {
          for (final value in errors.values) {
            final candidates = value is List ? value : [value];
            for (final candidate in candidates) {
              if (candidate is String && candidate.isNotEmpty) {
                message = candidate;
                break;
              }
            }
            if (message != null) break;
          }
        }
        message ??= data['message'] is String
            ? data['message'] as String
            : null;
        throw ApiFailure(
          message ?? 'Unable to complete this request.',
          response.statusCode,
        );
      }
      return data;
    } on TimeoutException {
      throw const ApiFailure(
        'Connection timed out. Refresh before retrying an update.',
      );
    } on http.ClientException {
      throw const ApiFailure(
        'Cannot reach the server. Check your connection and refresh before retrying.',
      );
    }
  }

  Future<Map<String, dynamic>> requestOtp(String phone) =>
      request('POST', 'auth/request', {'phone': phone});
  Future<void> verifyOtp(String challenge, String code) async {
    final result = await request('POST', 'auth/verify', {
      'challenge_id': challenge,
      'code': code,
    });
    await _saveSession(result['token']);
  }

  Future<void> signIn(String phone, String password) async {
    final result = await request('POST', 'auth/login', {
      'phone': phone,
      'password': password,
    });
    await _saveSession(result['token']);
  }

  Future<void> _saveSession(dynamic token) async {
    if (token is! String || token.isEmpty) {
      throw const ApiFailure(
        'Sign-in could not be confirmed. Please try again.',
      );
    }
    try {
      await store.write(token);
    } catch (_) {
      _token = token;
      try {
        await request('POST', 'logout');
      } catch (_) {}
      _token = null;
      throw const ApiFailure(
        'Could not securely save your session. Please try again.',
      );
    }
    _token = token;
  }

  Future<void> forgetSession() async {
    _token = null;
    await store.clear();
  }

  Future<void> logout() async {
    await request('POST', 'logout');
    await forgetSession();
  }

  Future<RiderProfile> profile() async =>
      RiderProfile.fromJson((await request('GET', 'me'))['data']);
  Future<RiderProfile> availability(bool online) async => RiderProfile.fromJson(
    (await request('PATCH', 'availability', {'is_online': online}))['data'],
  );
  Future<OrderPage> orders([int page = 1, String scope = 'all']) async {
    final result = await request(
      'GET',
      'orders?page=$page&scope=${Uri.encodeQueryComponent(scope)}',
    );
    return OrderPage(
      (result['data'] as List).map((e) => RiderOrder.fromJson(e)).toList(),
      result['next_page'] as int?,
    );
  }

  Future<RiderOrder> order(String reference) async => RiderOrder.fromJson(
    (await request('GET', 'orders/${Uri.encodeComponent(reference)}'))['data'],
  );
  Future<RiderOrder> pickup(RiderOrder order, RiderStop stop) async =>
      RiderOrder.fromJson(
        (await request(
          'POST',
          'orders/${Uri.encodeComponent(order.reference)}/pickup',
          {'revision': order.revision, 'stop_id': stop.id},
        ))['data'],
      );
  Future<RiderOrder> complete(
    RiderOrder order, {
    required bool collected,
    required String receipt,
  }) async => RiderOrder.fromJson(
    (await request(
      'POST',
      'orders/${Uri.encodeComponent(order.reference)}/complete',
      {
        'revision': order.revision,
        'payment_collected': collected,
        'receipt': receipt,
      },
    ))['data'],
  );
}

class RiderProfile {
  RiderProfile.fromJson(Map<String, dynamic> json)
    : name = json['name'],
      phone = json['phone'],
      vehicle = json['vehicle'] ?? 'Vehicle not provided',
      online = json['is_online'],
      locations = (json['locations'] as List? ?? [])
          .map(
            (row) => [
              row['name'],
              row['city'],
              row['state'],
            ].where((value) => value is String && value.isNotEmpty).join(', '),
          )
          .toList();
  final String name, phone, vehicle;
  final bool online;
  final List<String> locations;
}

class OrderPage {
  const OrderPage(this.orders, this.nextPage);
  final List<RiderOrder> orders;
  final int? nextPage;
}

String formatAddress(Map<String, dynamic>? address) => address == null
    ? ''
    : [
        address['street'],
        address['locality'],
        address['city'],
        address['state'],
        address['pin'],
      ].where((part) => part != null && part.toString().isNotEmpty).join(', ');
String money(int paise) => '₹${(paise / 100).toStringAsFixed(2)}';

class RiderOrder {
  RiderOrder.fromJson(Map<String, dynamic> json)
    : reference = json['reference'],
      vendorReviewRequired = json['vendor_review_required'] ?? false,
      revision = json['revision'],
      status = json['status'],
      address = Map<String, dynamic>.from(json['address']),
      codPaise = json['cod_paise'],
      totalPaise = json['total_paise'],
      receipt = json['receipt'],
      deliveredAt = DateTime.tryParse(json['delivered_at'] ?? ''),
      stops = (json['stops'] as List)
          .map((e) => RiderStop.fromJson(e))
          .toList();
  final String reference, status;
  final bool vendorReviewRequired;
  final int revision, codPaise, totalPaise;
  final String? receipt;
  final DateTime? deliveredAt;
  final Map<String, dynamic> address;
  final List<RiderStop> stops;
  bool get active =>
      ['received', 'picking', 'out_for_delivery'].contains(status);
  String get label => status.replaceAll('_', ' ');
  RiderStop? get nextStop {
    for (final stop in stops) {
      if (!stop.pickedUp) return stop;
    }
    return null;
  }
}

class RiderStop {
  RiderStop.fromJson(Map<String, dynamic> json)
    : id = json['id'],
      name = json['name'],
      phone = json['phone'],
      address = json['address'] == null
          ? ''
          : formatAddress(Map<String, dynamic>.from(json['address'])),
      pickedUp = json['picked_up'],
      pickupReady = json['pickup_ready'] ?? true,
      requiresPreparation = json['requires_preparation'] ?? false,
      preparationStatus = json['preparation_status'] ?? 'awaiting_vendor',
      items = (json['items'] as List)
          .map(
            (e) =>
                '${e['name']} · ${e['pack_size']} × ${e['quantity']}${(e['options'] as List? ?? []).isNotEmpty ? ' · ${(e['options'] as List).map((o) => o['name']).join(', ')}' : ''}',
          )
          .toList();
  final String id, name, address;
  final String? phone;
  final bool pickedUp, pickupReady, requiresPreparation;
  final String preparationStatus;
  final List<String> items;
}
