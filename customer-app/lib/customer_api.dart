import 'dart:async';
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;
import 'package:uuid/uuid.dart';

class ApiError implements Exception {
  const ApiError(this.message, [this.status = 0]);
  final String message;
  final int status;
  @override
  String toString() => message;
}

abstract class AppStorage {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
}

class SecureAppStorage implements AppStorage {
  const SecureAppStorage();
  static const storage = FlutterSecureStorage();
  @override
  Future<String?> read(String key) => storage.read(key: key);
  @override
  Future<void> write(String key, String value) =>
      storage.write(key: key, value: value);
  @override
  Future<void> delete(String key) => storage.delete(key: key);
}

class CustomerApi {
  CustomerApi({
    required this.baseUrl,
    http.Client? client,
    AppStorage? storage,
    this.timeout = const Duration(seconds: 20),
  }) : client = client ?? http.Client(),
       storage = storage ?? const SecureAppStorage();
  factory CustomerApi.configured() {
    const configured = String.fromEnvironment('API_BASE_URL');
    final local = !kIsWeb && defaultTargetPlatform == TargetPlatform.android
        ? 'http://10.0.2.2:8000/api'
        : 'http://127.0.0.1:8000/api';
    return CustomerApi(
      baseUrl: configured.isEmpty ? (kReleaseMode ? '' : local) : configured,
    );
  }
  final String baseUrl;
  final Duration timeout;
  final http.Client client;
  final AppStorage storage;
  String? token;
  bool get signedIn => token != null;
  String get tokenKey => 'customer:$baseUrl:token';
  Future<void> restore() async {
    token = await storage.read(tokenKey);
  }

  void close() => client.close();
  Future<void> forget() async {
    token = null;
    await storage.delete(tokenKey);
  }

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
      throw const ApiError(
        'The server connection is not configured. Please contact support.',
      );
    }
    try {
      final request = http.Request(
        method,
        Uri.parse('${baseUrl.replaceFirst(RegExp(r'/$'), '')}/$path'),
      );
      request.headers.addAll({
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
      });
      if (body != null) request.body = jsonEncode(body);
      final response = await (() async {
        return http.Response.fromStream(await client.send(request));
      })().timeout(timeout);

      if (response.statusCode == 401) {
        try {
          await forget();
        } catch (_) {
          throw const ApiError(
            'Your session expired. Please sign in again.',
            401,
          );
        }
        throw const ApiError(
          'Your session expired. Please sign in again.',
          401,
        );
      }
      if (response.statusCode >= 500) {
        throw ApiError(
          'Dropbell is temporarily unavailable. Please try again shortly.',
          response.statusCode,
        );
      }
      if (response.statusCode == 429) {
        throw const ApiError(
          'Too many requests. Please wait a moment before trying again.',
          429,
        );
      }
      Map<String, dynamic> data;
      try {
        data = jsonDecode(response.body) as Map<String, dynamic>;
      } catch (_) {
        throw ApiError(
          'The server returned an unexpected response. Please try refreshing.',
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
        throw ApiError(
          message ?? 'Unable to complete this request.',
          response.statusCode,
        );
      }
      return data;
    } on TimeoutException {
      throw const ApiError(
        'Connection timed out. If you were placing an order, use Retry checkout to confirm it safely.',
      );
    } on http.ClientException {
      throw const ApiError(
        'Cannot reach the server. Check your connection and try again.',
      );
    }
  }

  Future<Map<String, dynamic>> requestCode(String phone) =>
      request('POST', 'customer/auth/request', {'phone': phone});
  Future<void> verifyCode(String challenge, String code) async {
    final result = await request('POST', 'customer/auth/verify', {
      'challenge_id': challenge,
      'code': code,
    });
    final verified = result['token'];
    if (verified is! String || verified.isEmpty) {
      throw const ApiError('Sign-in could not be confirmed. Please try again.');
    }
    try {
      await storage.write(tokenKey, verified);
    } catch (_) {
      token = verified;
      try {
        await request('POST', 'customer/logout');
      } catch (_) {}
      token = null;
      throw const ApiError(
        'Could not securely save your sign-in. Please try again.',
      );
    }
    token = verified;
  }

  Future<void> emailSignIn(
    String email,
    String password, [
    Map<String, dynamic>? registration,
  ]) async {
    final result = await request(
      'POST',
      registration == null ? 'customer/auth/login' : 'customer/auth/register',
      {'email': email, 'password': password, ...?registration},
    );
    final verified = result['token'];
    if (verified is! String || verified.isEmpty) {
      throw const ApiError('Sign-in could not be confirmed. Please try again.');
    }
    try {
      await storage.write(tokenKey, verified);
    } catch (_) {
      token = verified;
      try {
        await request('POST', 'customer/logout');
      } catch (_) {}
      token = null;
      throw const ApiError(
        'Could not securely save your sign-in. Please try again.',
      );
    }
    token = verified;
  }

  Future<void> logout() async {
    await request('POST', 'customer/logout');
    await forget();
  }

  Future<Catalogue> catalogue(
    String? pin, {
    String? locality,
    int? vendorId,
  }) async {
    final query = pin == null
        ? ''
        : '?postal_code=${Uri.encodeQueryComponent(pin)}';
    final result = await request(
      'GET',
      'products$query${locality == null ? '' : '${query.isEmpty ? '?' : '&'}locality_id=$locality'}${vendorId == null ? '' : '${query.isEmpty && locality == null ? '?' : '&'}vendor_id=$vendorId'}',
    );
    if (vendorId != null) {
      for (final product in result['data'] as List) {
        product['selected_vendor_id'] = vendorId;
      }
    }
    return Catalogue.fromJson(result);
  }

  Future<CustomerProfile> profile() async =>
      CustomerProfile.fromJson((await request('GET', 'customer/me'))['data']);
  Future<CustomerProfile> rename(String name) async => CustomerProfile.fromJson(
    (await request('PATCH', 'customer/me', {'name': name}))['data'],
  );
  Future<CustomerProfile> saveAddress(
    Map<String, dynamic> address, [
    String? id,
  ]) async => CustomerProfile.fromJson(
    (await request(
      id == null ? 'POST' : 'PUT',
      id == null ? 'customer/addresses' : 'customer/addresses/$id',
      address,
    ))['data'],
  );
  Future<CustomerProfile> deleteAddress(String id) async =>
      CustomerProfile.fromJson(
        (await request('DELETE', 'customer/addresses/$id'))['data'],
      );
  Future<List<CustomerOrder>> orders() async =>
      ((await request('GET', 'customer/orders'))['data'] as List)
          .map((e) => CustomerOrder(e))
          .toList();
  Future<CustomerOrder> order(String reference) async => CustomerOrder(
    (await request(
      'GET',
      'customer/orders/${Uri.encodeComponent(reference)}',
    ))['data'],
  );
  Future<Map<String, dynamic>> quote(
    List<Map<String, dynamic>> items,
    String pin,
    String coupon, [
    Map<String, dynamic>? address,
    int? vendorId,
  ]) async => (await request('POST', 'customer/quote', {
    'items': items,
    'postal_code': pin,
    'address': ?address,
    'vendor_id': ?vendorId,
    'coupon': coupon.isEmpty ? null : coupon,
  }))['data'];
  Future<CustomerOrder> place(Map<String, dynamic> payload) async =>
      CustomerOrder(
        (await request('POST', 'customer/orders', payload))['data'],
      );
  Future<void> aftercare(String reference, String type, String reason) async {
    await request(
      'POST',
      'customer/orders/${Uri.encodeComponent(reference)}/requests',
      {'type': type, 'reason': reason},
    );
  }

  Future<List<Map<String, dynamic>>> transactions() async =>
      ((await request('GET', 'customer/transactions'))['data'] as List)
          .map((e) => Map<String, dynamic>.from(e))
          .toList();
}

String rupees(int paise) => '₹${(paise / 100).toStringAsFixed(2)}';

class Product {
  Product(this.json);
  final Map<String, dynamic> json;
  int get id => json['id'];
  String get name => json['name'];
  String get category => json['category'];
  String get section =>
      json['shopping_section'] == 'food' ? 'food' : 'groceries';
  int? get vendorId => json['selected_vendor_id'];
  int? get subcategory => json['sub_category_id'];
  String get pack => [
    json['pack_size'],
    if ((json['selected_option_names'] as List? ?? []).isNotEmpty)
      (json['selected_option_names'] as List).join(', '),
  ].join(' · ');
  List<Map<String, dynamic>> get optionGroups =>
      (json['option_groups'] as List? ?? [])
          .map((g) => Map<String, dynamic>.from(g))
          .toList();
  List<String> get optionIds =>
      List<String>.from(json['selected_option_ids'] ?? []);
  int get price => (json['price_paise'] as num).toInt();
  String? get image => json['image_url'];
  String get description => json['description'] ?? '';
  bool get sample => json['is_sample'] == true;
  bool get available => !sample && json['available'] == true && maxQuantity > 0;
  int get maxQuantity => (json['max_order_quantity'] as num?)?.toInt() ?? 0;
}

class Catalogue {
  Catalogue.fromJson(Map<String, dynamic> json)
    : products = (json['data'] as List).map((e) => Product(e)).toList(),
      categories = (json['categories'] as List)
          .map((e) => e['name'] as String)
          .toList(),
      subcategories = (json['sub_categories'] as List)
          .map((e) => Map<String, dynamic>.from(e))
          .toList(),
      categoryDetails = (json['categories'] as List)
          .map((e) => Map<String, dynamic>.from(e))
          .toList(),
      vendors = (json['vendors'] as List? ?? [])
          .map((e) => Map<String, dynamic>.from(e))
          .toList();
  final List<Map<String, dynamic>> categoryDetails, vendors;
  List<String> categoriesFor(String section) => categoryDetails
      .where((e) => (e['shopping_section'] ?? 'groceries') == section)
      .map((e) => e['name'] as String)
      .toList();
  final List<Product> products;
  final List<String> categories;
  final List<Map<String, dynamic>> subcategories;
}

class SavedAddress {
  SavedAddress(this.json);
  final Map<String, dynamic> json;
  String get id => json['id'].toString();
  String get label => json['label'];
  String get pin => json['pin'];
  String get text => [
    json['street'],
    json['locality'],
    json['city'],
    pin,
  ].where((value) => value != null && value.toString().isNotEmpty).join(', ');
  Map<String, dynamic> get payload => {
    for (final key in [
      'name',
      'phone',
      'street',
      'city',
      'pin',
      'locality_id',
      'state_id',
      'city_id',
      'state',
      'locality',
    ])
      key: json[key],
  };
}

class CustomerProfile {
  CustomerProfile.fromJson(Map<String, dynamic> json)
    : name = json['name'],
      phone = json['phone'] ?? '',
      demo = json['isDemo'] == true,
      addresses = (json['addresses'] as List)
          .map((e) => SavedAddress(e))
          .toList();
  final String name, phone;
  final bool demo;
  final List<SavedAddress> addresses;
}

class CustomerOrder {
  CustomerOrder(this.json);
  final Map<String, dynamic> json;
  String get id => json['id'];
  String get status => json['status'];
  bool get sample => json['isSample'] == true;
  int get total => json['bill']['total'];
  Map<String, dynamic> get address =>
      Map<String, dynamic>.from(json['address']);
  List<dynamic> get items => json['items'];
  List<dynamic> get requests => json['requests'] ?? [];
}

class CartLine {
  CartLine(this.product, this.quantity);
  final Product product;
  final int quantity;
  Map<String, dynamic> get item => {
    'id': product.id,
    'quantity': quantity,
    if (product.optionGroups.isNotEmpty) 'options': product.optionIds,
    if (product.vendorId != null) 'vendor_id': product.vendorId,
    if (product.optionGroups.isNotEmpty)
      'menu_revision': product.json['menu_revision'] ?? 0,
  };
  Map<String, dynamic> get json => {
    'product': product.json,
    'quantity': quantity,
  };
}

class ShoppingCart extends ChangeNotifier {
  ShoppingCart(this.storage, this.key);
  final AppStorage storage;
  final String key;
  Map<int, CartLine> lines = {};
  Set<String> consumed = {};
  bool busy = false;
  int? get vendorId {
    final vendors = lines.values.map((line) => line.product.vendorId).toSet();
    return vendors.length == 1 ? vendors.first : null;
  }

  int get count => lines.values.fold(0, (sum, line) => sum + line.quantity);
  int get estimate => lines.values.fold(
    0,
    (sum, line) => sum + line.product.price * line.quantity,
  );
  Future<void> restore() async {
    final saved = await storage.read(key);
    if (saved == null) return;
    final decoded = jsonDecode(saved);
    final rows = decoded is List ? decoded : decoded['lines'] as List;
    consumed = decoded is List
        ? {}
        : Set<String>.from(decoded['consumed'] ?? []);
    lines = {
      for (final row in rows)
        (row['product']['id'] as int): CartLine(
          Product(Map<String, dynamic>.from(row['product'])),
          row['quantity'] as int,
        ),
    };
    notifyListeners();
  }

  Future<void> setQuantity(Product product, int quantity) async {
    if (busy) return;
    if (quantity > 0 &&
        lines.isNotEmpty &&
        product.vendorId != vendorId &&
        !(lines.containsKey(product.id) &&
            lines[product.id]!.product.vendorId == product.vendorId)) {
      throw const ApiError(
        'Your bag belongs to another shop. Clear it before ordering from this restaurant.',
      );
    }
    if (quantity < 0 ||
        quantity > product.maxQuantity ||
        (quantity > 0 && !product.available)) {
      throw const ApiError(
        'This quantity is not available. Refresh products and review your bag.',
      );
    }
    busy = true;
    notifyListeners();
    try {
      final updated = {...lines};
      if (quantity == 0) {
        updated.remove(product.id);
      } else {
        updated[product.id] = CartLine(product, quantity);
      }
      await storage.write(key, encode(updated, consumed));
      lines = updated;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> clear() async {
    await storage.write(key, encode({}, consumed));
    lines = {};
    notifyListeners();
  }

  String encode(Map<int, CartLine> items, Set<String> completed) => jsonEncode({
    'lines': items.values.map((e) => e.json).toList(),
    'consumed': completed.toList(),
  });

  Future<void> consume(
    String requestKey,
    List<dynamic> purchased, [
    int? purchasedVendorId,
  ]) async {
    if (consumed.contains(requestKey)) return;
    final updated = {...lines};
    for (final item in purchased) {
      if (vendorId != purchasedVendorId) continue;
      final line = updated[item['id']];
      if (line == null) continue;
      if ((item['vendor_id'] ?? purchasedVendorId) != line.product.vendorId) {
        continue;
      }
      final previousOptions = List<String>.from(item['options'] ?? [])..sort();
      final currentOptions = [...line.product.optionIds]..sort();
      if (previousOptions.join('|') != currentOptions.join('|') ||
          (item['menu_revision'] ?? 0) !=
              (line.product.json['menu_revision'] ?? 0)) {
        continue;
      }
      final remaining = line.quantity - (item['quantity'] as int);
      if (remaining <= 0) {
        updated.remove(item['id']);
      } else {
        updated[item['id']] = CartLine(line.product, remaining);
      }
    }
    final completed = {...consumed, requestKey};
    if (completed.length > 20) completed.remove(completed.first);
    await storage.write(key, encode(updated, completed));
    lines = updated;
    consumed = completed;
    notifyListeners();
  }
}

class CheckoutAttempt {
  CheckoutAttempt(this.api, String phone)
    : key = 'customer:${api.baseUrl}:pending:$phone';
  final CustomerApi api;
  final String key;
  Map<String, dynamic>? pending;
  Future<void> restore() async {
    final saved = await api.storage.read(key);
    pending = saved == null
        ? null
        : Map<String, dynamic>.from(jsonDecode(saved));
  }

  Future<CustomerOrder> submit(Map<String, dynamic> payload) async {
    if (pending == null) {
      final draft = {...payload, 'request_key': const Uuid().v4()};
      await api.storage.write(key, jsonEncode(draft));
      pending = draft;
    }
    try {
      return await api.place(pending!);
    } on ApiError catch (e) {
      if (e.status == 422) {
        await api.storage.delete(key);
        pending = null;
      }
      rethrow;
    }
  }

  Future<void> finish() async {
    await api.storage.delete(key);
    pending = null;
  }
}
