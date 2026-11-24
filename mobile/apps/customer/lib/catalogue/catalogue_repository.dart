import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import 'models.dart';

/// The public catalogue and the customer's favourites. Lists the home screen needs are cached
/// on the device, so Home still shows the last stores when the network is down (spec 09 §9.8).
class CatalogueRepository {
  CatalogueRepository(this._client, this._store);

  final ApiClient _client;
  final KeyValueStore _store;

  static String _q(String path, Map<String, Object?> params) {
    final query = <String, String>{
      for (final e in params.entries)
        if (e.value != null && e.value.toString().isNotEmpty)
          e.key: e.value.toString(),
    };
    return Uri(
      path: path,
      queryParameters: query.isEmpty ? null : query,
    ).toString();
  }

  Future<List<VendorType>> vendorTypes() async {
    final body = await _cached(
      'catalogue.vendor_types',
      () => _client.getEnvelope('/catalogue/vendor-types'),
    );
    return _items(body).map(VendorType.fromJson).toList();
  }

  Future<List<Category>> categories(String vendorType) async {
    final body = await _client.getEnvelope(
      '/catalogue/vendor-types/$vendorType/categories',
    );
    return _items(body).map(Category.fromJson).toList();
  }

  /// Stores within browsing distance of a point, nearest first.
  Future<Page<Vendor>> vendors({
    required double lat,
    required double lng,
    String? type,
    String? category,
    String? query,
    int page = 1,
    int perPage = 20,
  }) async {
    final path = _q('/catalogue/vendors', {
      'lat': lat,
      'lng': lng,
      'vendor_type': type,
      'category': category,
      'q': query,
      'page': page,
      'per_page': perPage,
    });
    // Only the plain first page of "near me" is cached (Home, offline).
    final cacheable =
        type == null && category == null && query == null && page == 1;
    final body = cacheable
        ? await _cached('catalogue.near', () => _client.getEnvelope(path))
        : await _client.getEnvelope(path);
    final links = body['links'] is Map
        ? Map<String, Object?>.from(body['links']! as Map)
        : const <String, Object?>{};
    return Page(
      _items(body).map(Vendor.fromJson).toList(),
      next: links['next'] == null ? null : '${page + 1}',
    );
  }

  Future<Vendor> vendor(String slug, {double? lat, double? lng}) async =>
      Vendor.fromJson(
        await _client.getJson(
          _q('/catalogue/vendors/$slug', {'lat': lat, 'lng': lng}),
        ),
      );

  Future<DeliveryQuote> deliveryQuote(
    String slug, {
    required double lat,
    required double lng,
  }) async => DeliveryQuote.fromJson(
    await _client.getJson(
      _q('/catalogue/vendors/$slug/delivery-quote', {'lat': lat, 'lng': lng}),
    ),
  );

  Future<Page<Product>> products(
    String slug, {
    String? menu,
    String? query,
    String? cursor,
  }) async {
    final body = await _client.getEnvelope(
      _q('/catalogue/vendors/$slug/products', {
        'menu': menu,
        'q': query,
        'cursor': cursor,
        'per_page': 50,
      }),
    );
    final meta = body['meta'] is Map
        ? Map<String, Object?>.from(body['meta']! as Map)
        : const <String, Object?>{};
    return Page(
      _items(body).map(Product.fromJson).toList(),
      next: meta['next_cursor'] as String?,
    );
  }

  Future<Product> product(String id) async =>
      Product.fromJson(await _client.getJson('/catalogue/products/$id'));

  Future<Page<Review>> reviews(String slug, {String? cursor}) async {
    final body = await _client.getEnvelope(
      _q('/catalogue/vendors/$slug/reviews', {'cursor': cursor}),
    );
    final meta = body['meta'] is Map
        ? Map<String, Object?>.from(body['meta']! as Map)
        : const <String, Object?>{};
    return Page(
      _items(body).map(Review.fromJson).toList(),
      next: meta['next_cursor'] as String?,
    );
  }

  Future<SearchResults> search(
    String query, {
    required double lat,
    required double lng,
  }) async => SearchResults.fromJson(
    await _client.getJson(
      _q('/catalogue/search', {'q': query, 'lat': lat, 'lng': lng}),
    ),
  );

  Future<SearchResults> favourites() async =>
      SearchResults.fromJson(await _client.getJson('/me/favourites'));

  Future<void> setFavourite({
    required bool vendor,
    required String id,
    required bool on,
  }) => _client.send(
    on ? 'PUT' : 'DELETE',
    '/me/favourites/${vendor ? 'vendors' : 'products'}/$id',
  );

  /// Fetches and keeps a copy; on a network error returns the last copy if there is one.
  Future<Map<String, Object?>> _cached(
    String key,
    Future<Map<String, Object?>> Function() fetch,
  ) async {
    try {
      final body = await fetch();
      await _store.write(key, jsonEncode(body));
      return body;
    } on ApiException catch (e) {
      if (e.code != ApiError.network.code) rethrow;
      final raw = await _store.read(key);
      if (raw == null) rethrow;
      return Map<String, Object?>.from(jsonDecode(raw) as Map);
    }
  }

  static List<Map<String, Object?>> _items(Map<String, Object?> body) {
    final data = body['data'];
    return data is List
        ? data.map((e) => Map<String, Object?>.from(e as Map)).toList()
        : const [];
  }
}

final catalogueRepositoryProvider = Provider<CatalogueRepository>(
  (ref) => CatalogueRepository(
    ref.watch(apiClientProvider),
    ref.watch(keyValueStoreProvider),
  ),
);
