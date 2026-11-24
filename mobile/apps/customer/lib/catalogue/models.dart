/// Catalogue data as the API returns it (spec 14 §14.2 "Catalogue"). Money is in kobo.
library;

Map<String, Object?> _map(Object? v) =>
    v is Map ? Map<String, Object?>.from(v) : const {};
List<Object?> _list(Object? v) => v is List ? v : const [];
int _kobo(Object? money) => (_map(money)['amount_kobo'] as num?)?.toInt() ?? 0;
String _formatted(Object? money) => _map(money)['formatted']?.toString() ?? '';

class Money {
  const Money(this.kobo, this.formatted);

  factory Money.fromJson(Object? json) => Money(_kobo(json), _formatted(json));

  /// ₦ with thousands separators and kobo only when needed, e.g. ₦1,500 or ₦1,500.50.
  factory Money.ofKobo(int kobo) {
    final naira = kobo ~/ 100;
    final rest = (kobo % 100).abs();
    final digits = naira.abs().toString().replaceAllMapped(
      RegExp(r'\B(?=(\d{3})+(?!\d))'),
      (_) => ',',
    );
    final sign = kobo < 0 ? '-' : '';
    return Money(
      kobo,
      rest == 0
          ? '$sign₦$digits'
          : '$sign₦$digits.${rest.toString().padLeft(2, '0')}',
    );
  }

  final int kobo;
  final String formatted;

  @override
  String toString() => formatted;
}

class VendorType {
  const VendorType({required this.slug, required this.name, this.description});

  factory VendorType.fromJson(Map<String, Object?> j) => VendorType(
    slug: j['slug'].toString(),
    name: j['name'].toString(),
    description: j['description'] as String?,
  );

  final String slug;
  final String name;
  final String? description;
}

class Category {
  const Category({required this.slug, required this.name});

  factory Category.fromJson(Map<String, Object?> j) =>
      Category(slug: j['slug'].toString(), name: j['name'].toString());

  final String slug;
  final String name;
}

/// open | busy | closed
enum OpenState {
  open,
  busy,
  closed;

  static OpenState parse(Object? v) =>
      OpenState.values.where((s) => s.name == v).firstOrNull ??
      OpenState.closed;
}

class Vendor {
  const Vendor({
    required this.id,
    required this.slug,
    required this.name,
    required this.type,
    required this.openState,
    this.description,
    this.logoUrl,
    this.coverUrl,
    this.address,
    this.ratingAverage = 0,
    this.ratingCount = 0,
    this.distanceM,
    this.deliverable,
    this.acceptsDelivery = true,
    this.acceptsPickup = false,
    this.allowsScheduled = false,
    this.prepMinutes = 0,
    this.deliveryMinutes = 0,
    this.minOrder = const Money(0, '₦0'),
    this.featured = false,
    this.hours = const [],
    this.menus = const [],
  });

  factory Vendor.fromJson(Map<String, Object?> j) => Vendor(
    id: j['id'].toString(),
    slug: j['slug'].toString(),
    name: j['name'].toString(),
    type: j['vendor_type']?.toString() ?? '',
    description: j['description'] as String?,
    logoUrl: j['logo_url'] as String?,
    coverUrl: j['cover_url'] as String?,
    address: j['address_line'] as String?,
    openState: OpenState.parse(j['open_state']),
    ratingAverage: (_map(j['rating'])['average'] as num?)?.toDouble() ?? 0,
    ratingCount: (_map(j['rating'])['count'] as num?)?.toInt() ?? 0,
    distanceM: (j['distance_m'] as num?)?.toInt(),
    deliverable: j['deliverable'] as bool?,
    acceptsDelivery: j['accepts_delivery'] != false,
    acceptsPickup: j['accepts_pickup'] == true,
    allowsScheduled: j['allows_scheduled_orders'] == true,
    prepMinutes: (j['prep_time_minutes'] as num?)?.toInt() ?? 0,
    deliveryMinutes: (j['delivery_time_minutes'] as num?)?.toInt() ?? 0,
    minOrder: Money.fromJson(j['min_order']),
    featured: j['is_featured'] == true,
    hours: _list(j['hours']).map((h) {
      final m = _map(h);
      return OpeningHours(
        weekday: (m['weekday'] as num).toInt(),
        opens: m['opens_at'].toString(),
        closes: m['closes_at'].toString(),
      );
    }).toList(),
    menus: _list(j['menus'])
        .map(
          (m) => MenuSection(
            id: _map(m)['id'].toString(),
            name: _map(m)['name'].toString(),
          ),
        )
        .toList(),
  );

  final String id;
  final String slug;
  final String name;
  final String type;
  final String? description;
  final String? logoUrl;
  final String? coverUrl;
  final String? address;
  final OpenState openState;
  final double ratingAverage;
  final int ratingCount;
  final int? distanceM;
  final bool? deliverable;
  final bool acceptsDelivery;
  final bool acceptsPickup;
  final bool allowsScheduled;
  final int prepMinutes;
  final int deliveryMinutes;
  final Money minOrder;
  final bool featured;
  final List<OpeningHours> hours;
  final List<MenuSection> menus;

  bool get isOpen => openState == OpenState.open;
}

/// Weekday 0 = Sunday … 6 = Saturday; closing at or before opening runs past midnight.
class OpeningHours {
  const OpeningHours({
    required this.weekday,
    required this.opens,
    required this.closes,
  });

  final int weekday;
  final String opens;
  final String closes;
}

class MenuSection {
  const MenuSection({required this.id, required this.name});

  final String id;
  final String name;
}

class ProductOption {
  const ProductOption({
    required this.id,
    required this.name,
    required this.price,
    this.description,
    this.perUnit = true,
  });

  final String id;
  final String name;
  final String? description;
  final Money price;
  final bool perUnit;
}

class OptionGroup {
  const OptionGroup({
    required this.id,
    required this.name,
    required this.options,
    this.required = false,
    this.minSelect = 0,
    this.maxSelect = 1,
  });

  factory OptionGroup.fromJson(Map<String, Object?> j) => OptionGroup(
    id: j['id'].toString(),
    name: j['name'].toString(),
    required: j['is_required'] == true,
    minSelect: (j['min_select'] as num?)?.toInt() ?? 0,
    maxSelect: (j['max_select'] as num?)?.toInt() ?? 1,
    options: _list(j['options']).map((o) {
      final m = _map(o);
      return ProductOption(
        id: m['id'].toString(),
        name: m['name'].toString(),
        description: m['description'] as String?,
        price: Money.fromJson(m['price']),
        perUnit: m['per_unit'] != false,
      );
    }).toList(),
  );

  final String id;
  final String name;
  final bool required;
  final int minSelect;
  final int maxSelect;
  final List<ProductOption> options;

  /// The fewest choices this group accepts (a required group needs at least one).
  int get minimum => required && minSelect < 1 ? 1 : minSelect;
}

class Product {
  const Product({
    required this.id,
    required this.name,
    required this.price,
    this.originalPrice,
    this.description,
    this.imageUrls = const [],
    this.inStock = true,
    this.requiresPrescription = false,
    this.ratingAverage = 0,
    this.ratingCount = 0,
    this.optionGroups = const [],
    this.vendorSlug,
    this.vendorName,
    this.pricingUnit,
  });

  factory Product.fromJson(Map<String, Object?> j) {
    final vendor = _map(j['vendor']);
    final service = _map(j['service']);
    return Product(
      id: j['id'].toString(),
      name: j['name'].toString(),
      description: j['description'] as String?,
      price: Money.fromJson(j['price']),
      originalPrice: j['original_price'] == null
          ? null
          : Money.fromJson(j['original_price']),
      imageUrls: _list(j['image_urls']).whereType<String>().toList(),
      inStock: j['in_stock'] != false,
      requiresPrescription: j['requires_prescription'] == true,
      ratingAverage: (_map(j['rating'])['average'] as num?)?.toDouble() ?? 0,
      ratingCount: (_map(j['rating'])['count'] as num?)?.toInt() ?? 0,
      optionGroups: _list(
        j['option_groups'],
      ).map((g) => OptionGroup.fromJson(_map(g))).toList(),
      vendorSlug: vendor['slug'] as String?,
      vendorName: vendor['name'] as String?,
      pricingUnit: service['pricing_unit'] as String?,
    );
  }

  final String id;
  final String name;
  final String? description;
  final Money price;
  final Money? originalPrice;
  final List<String> imageUrls;
  final bool inStock;
  final bool requiresPrescription;
  final double ratingAverage;
  final int ratingCount;
  final List<OptionGroup> optionGroups;
  final String? vendorSlug;
  final String? vendorName;

  /// Set for bookable services (fixed, hour, day, month).
  final String? pricingUnit;
}

class Review {
  const Review({
    required this.id,
    required this.rating,
    required this.createdAt,
    this.comment,
    this.author,
  });

  factory Review.fromJson(Map<String, Object?> j) => Review(
    id: j['id'].toString(),
    rating: (j['rating'] as num).toInt(),
    comment: j['comment'] as String?,
    author: j['author'] as String?,
    createdAt:
        DateTime.tryParse(j['created_at']?.toString() ?? '') ??
        DateTime.fromMillisecondsSinceEpoch(0),
  );

  final String id;
  final int rating;
  final String? comment;
  final String? author;
  final DateTime createdAt;
}

class DeliveryQuote {
  const DeliveryQuote({
    required this.deliverable,
    this.reason,
    this.fee,
    this.etaMin,
    this.etaMax,
  });

  factory DeliveryQuote.fromJson(Map<String, Object?> j) => DeliveryQuote(
    deliverable: j['deliverable'] == true,
    reason: j['reason'] as String?,
    fee: j['fee'] == null ? null : Money.fromJson(j['fee']),
    etaMin: (_map(j['eta_minutes'])['min'] as num?)?.toInt(),
    etaMax: (_map(j['eta_minutes'])['max'] as num?)?.toInt(),
  );

  final bool deliverable;
  final String? reason;
  final Money? fee;
  final int? etaMin;
  final int? etaMax;
}

class SearchResults {
  const SearchResults({this.vendors = const [], this.products = const []});

  factory SearchResults.fromJson(Map<String, Object?> j) => SearchResults(
    vendors: _list(j['vendors']).map((v) => Vendor.fromJson(_map(v))).toList(),
    products: _list(
      j['products'],
    ).map((p) => Product.fromJson(_map(p))).toList(),
  );

  final List<Vendor> vendors;
  final List<Product> products;

  bool get isEmpty => vendors.isEmpty && products.isEmpty;
}

/// A page of results and where the next one starts (null at the end).
class Page<T> {
  const Page(this.items, {this.next});

  final List<T> items;
  final String? next;
}
