import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import '../location/delivery_location.dart';
import 'catalogue_repository.dart';
import 'models.dart';

final vendorTypesProvider = FutureProvider<List<VendorType>>(
  (ref) => ref.watch(catalogueRepositoryProvider).vendorTypes(),
);

final categoriesProvider = FutureProvider.family<List<Category>, String>(
  (ref, type) => ref.watch(catalogueRepositoryProvider).categories(type),
);

/// What a store list shows: a type, a category, open stores only, and the order.
class VendorFilter {
  const VendorFilter({
    this.type,
    this.category,
    this.openOnly = false,
    this.sort = VendorSort.distance,
  });

  final String? type;
  final String? category;
  final bool openOnly;
  final VendorSort sort;

  VendorFilter copyWith({
    String? type,
    String? category,
    bool clearCategory = false,
    bool? openOnly,
    VendorSort? sort,
  }) => VendorFilter(
    type: type ?? this.type,
    category: clearCategory ? null : (category ?? this.category),
    openOnly: openOnly ?? this.openOnly,
    sort: sort ?? this.sort,
  );

  @override
  bool operator ==(Object other) =>
      other is VendorFilter &&
      other.type == type &&
      other.category == category &&
      other.openOnly == openOnly &&
      other.sort == sort;

  @override
  int get hashCode => Object.hash(type, category, openOnly, sort);
}

enum VendorSort { distance, rating }

/// Stores near the chosen location (first page, up to 50), filtered and sorted on the device.
final vendorsProvider = FutureProvider.family<List<Vendor>, VendorFilter>((
  ref,
  filter,
) async {
  final location = await ref.watch(deliveryLocationProvider.future);
  if (location == null) return const [];
  final page = await ref
      .watch(catalogueRepositoryProvider)
      .vendors(
        lat: location.lat,
        lng: location.lng,
        type: filter.type,
        category: filter.category,
        perPage: 50,
      );
  final list = page.items.where((v) => !filter.openOnly || v.isOpen).toList();
  if (filter.sort == VendorSort.rating) {
    list.sort((a, b) => b.ratingAverage.compareTo(a.ratingAverage));
  }
  return list;
});

final vendorProvider = FutureProvider.family<Vendor, String>((ref, slug) async {
  final location = ref.watch(deliveryLocationProvider).value;
  return ref
      .watch(catalogueRepositoryProvider)
      .vendor(slug, lat: location?.lat, lng: location?.lng);
});

/// Delivery fee and time to the chosen location; null when no location is chosen.
final deliveryQuoteProvider = FutureProvider.family<DeliveryQuote?, String>((
  ref,
  slug,
) async {
  final location = await ref.watch(deliveryLocationProvider.future);
  if (location == null) return null;
  return ref
      .watch(catalogueRepositoryProvider)
      .deliveryQuote(slug, lat: location.lat, lng: location.lng);
});

final storeProductsProvider =
    FutureProvider.family<List<Product>, ({String slug, String? menu})>((
      ref,
      key,
    ) async {
      final page = await ref
          .watch(catalogueRepositoryProvider)
          .products(key.slug, menu: key.menu);
      return page.items;
    });

final productProvider = FutureProvider.family<Product, String>(
  (ref, id) => ref.watch(catalogueRepositoryProvider).product(id),
);

final storeReviewsProvider = FutureProvider.family<List<Review>, String>(
  (ref, slug) async =>
      (await ref.watch(catalogueRepositoryProvider).reviews(slug)).items,
);

/// Saved stores and products, with toggles that update the list straight away.
class FavouritesController extends AsyncNotifier<SearchResults> {
  @override
  Future<SearchResults> build() =>
      ref.watch(catalogueRepositoryProvider).favourites();

  bool isVendor(String id) =>
      state.value?.vendors.any((v) => v.id == id) ?? false;

  bool isProduct(String id) =>
      state.value?.products.any((p) => p.id == id) ?? false;

  Future<void> toggleVendor(Vendor vendor) async {
    final on = !isVendor(vendor.id);
    final current = state.value ?? const SearchResults();
    state = AsyncData(
      SearchResults(
        vendors: on
            ? [vendor, ...current.vendors]
            : current.vendors.where((v) => v.id != vendor.id).toList(),
        products: current.products,
      ),
    );
    await _save(vendor: true, id: vendor.id, on: on);
  }

  Future<void> toggleProduct(Product product) async {
    final on = !isProduct(product.id);
    final current = state.value ?? const SearchResults();
    state = AsyncData(
      SearchResults(
        vendors: current.vendors,
        products: on
            ? [product, ...current.products]
            : current.products.where((p) => p.id != product.id).toList(),
      ),
    );
    await _save(vendor: false, id: product.id, on: on);
  }

  Future<void> _save({
    required bool vendor,
    required String id,
    required bool on,
  }) async {
    try {
      await ref
          .read(catalogueRepositoryProvider)
          .setFavourite(vendor: vendor, id: id, on: on);
    } on ApiException {
      // Put the list back as the server has it.
      ref.invalidateSelf();
      rethrow;
    }
  }
}

final favouritesProvider =
    AsyncNotifierProvider<FavouritesController, SearchResults>(
      FavouritesController.new,
    );

/// The last searches, newest first, kept on the device (spec 09 "Search").
class RecentSearches extends AsyncNotifier<List<String>> {
  static const storeKey = 'recent_searches';
  static const max = 8;

  @override
  Future<List<String>> build() async {
    final raw = await ref.read(keyValueStoreProvider).read(storeKey);
    if (raw == null) return const [];
    try {
      return List<String>.from(jsonDecode(raw) as List);
    } on Object {
      return const [];
    }
  }

  Future<void> add(String term) async {
    final t = term.trim();
    if (t.length < 2) return;
    final list = [
      t,
      ...?state.value?.where((s) => s.toLowerCase() != t.toLowerCase()),
    ].take(max).toList();
    state = AsyncData(list);
    await ref.read(keyValueStoreProvider).write(storeKey, jsonEncode(list));
  }

  Future<void> clear() async {
    state = const AsyncData([]);
    await ref.read(keyValueStoreProvider).remove(storeKey);
  }
}

final recentSearchesProvider =
    AsyncNotifierProvider<RecentSearches, List<String>>(RecentSearches.new);

/// Home carousel banners (`GET /app/banners`), optionally for one store type.
final bannersProvider = FutureProvider.family<List<Slide>, String?>((
  ref,
  type,
) async {
  final body = await ref
      .watch(apiClientProvider)
      .getEnvelope(
        type == null ? '/app/banners' : '/app/banners?vendor_type=$type',
      );
  final data = body['data'];
  return (data is List ? data : const [])
      .map((e) => Slide.fromJson(Map<String, Object?>.from(e as Map)))
      .toList();
});
