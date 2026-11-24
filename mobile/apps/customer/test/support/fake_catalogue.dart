import 'package:customer_app/catalogue/catalogue_repository.dart';
import 'package:customer_app/catalogue/models.dart';

const mamaPut = Vendor(
  id: 'v-1',
  slug: 'mama-put',
  name: 'Mama Put Kitchen',
  type: 'food',
  openState: OpenState.open,
  ratingAverage: 4.6,
  ratingCount: 120,
  distanceM: 850,
  deliverable: true,
  prepMinutes: 20,
  deliveryMinutes: 15,
  featured: true,
  minOrder: Money(100000, '₦1,000'),
  allowsScheduled: true,
  address: '12 Herbert Macaulay Way, Yaba',
  hours: [OpeningHours(weekday: 1, opens: '08:00', closes: '22:00')],
  menus: [
    MenuSection(id: 'm-rice', name: 'Rice'),
    MenuSection(id: 'm-drinks', name: 'Drinks'),
  ],
);

const suyaSpot = Vendor(
  id: 'v-2',
  slug: 'suya-spot',
  name: 'Suya Spot',
  type: 'food',
  openState: OpenState.closed,
  ratingAverage: 4.9,
  ratingCount: 40,
  distanceM: 2300,
);

const pharmaPlus = Vendor(
  id: 'v-3',
  slug: 'pharma-plus',
  name: 'Pharma Plus',
  type: 'pharmacy',
  openState: OpenState.open,
  ratingAverage: 3.9,
  ratingCount: 8,
  distanceM: 1200,
);

const jollof = Product(
  id: 'p-1',
  name: 'Jollof rice',
  description: 'Smoky party jollof',
  price: Money(150000, '₦1,500'),
  originalPrice: Money(180000, '₦1,800'),
  vendorSlug: 'mama-put',
  vendorName: 'Mama Put Kitchen',
  optionGroups: [
    OptionGroup(
      id: 'g-protein',
      name: 'Protein',
      required: true,
      maxSelect: 1,
      options: [
        ProductOption(
          id: 'o-chicken',
          name: 'Chicken',
          price: Money(50000, '₦500'),
        ),
        ProductOption(id: 'o-beef', name: 'Beef', price: Money(30000, '₦300')),
      ],
    ),
    OptionGroup(
      id: 'g-extras',
      name: 'Extras',
      maxSelect: 2,
      options: [
        ProductOption(
          id: 'o-plantain',
          name: 'Plantain',
          price: Money(20000, '₦200'),
        ),
        ProductOption(id: 'o-egg', name: 'Egg', price: Money(10000, '₦100')),
        ProductOption(id: 'o-salad', name: 'Salad', price: Money(0, '₦0')),
      ],
    ),
  ],
);

const zobo = Product(
  id: 'p-2',
  name: 'Zobo',
  price: Money(50000, '₦500'),
  inStock: false,
  vendorSlug: 'mama-put',
  vendorName: 'Mama Put Kitchen',
);

/// A catalogue with three stores and two products; records favourite changes.
class FakeCatalogue implements CatalogueRepository {
  final favouriteCalls = <String>[];
  var savedFavourites = const SearchResults();
  final searches = <String>[];
  bool failVendors = false;

  @override
  Future<List<VendorType>> vendorTypes() async => const [
    VendorType(slug: 'food', name: 'Food'),
    VendorType(slug: 'pharmacy', name: 'Pharmacy'),
  ];

  @override
  Future<List<Category>> categories(String vendorType) async =>
      vendorType == 'food'
      ? const [Category(slug: 'rice', name: 'Rice dishes')]
      : const [];

  @override
  Future<Page<Vendor>> vendors({
    required double lat,
    required double lng,
    String? type,
    String? category,
    String? query,
    int page = 1,
    int perPage = 20,
  }) async {
    if (failVendors) throw Exception('down');
    var list = const [
      mamaPut,
      suyaSpot,
      pharmaPlus,
    ].where((v) => type == null || v.type == type).toList();
    if (category != null) {
      list = list.where((v) => v.slug == 'mama-put').toList();
    }
    return Page(list);
  }

  @override
  Future<Vendor> vendor(String slug, {double? lat, double? lng}) async =>
      const [mamaPut, suyaSpot, pharmaPlus].firstWhere((v) => v.slug == slug);

  @override
  Future<DeliveryQuote> deliveryQuote(
    String slug, {
    required double lat,
    required double lng,
  }) async => const DeliveryQuote(
    deliverable: true,
    fee: Money(50000, '₦500'),
    etaMin: 30,
    etaMax: 40,
  );

  @override
  Future<Page<Product>> products(
    String slug, {
    String? menu,
    String? query,
    String? cursor,
  }) async => Page(menu == 'm-drinks' ? const [zobo] : const [jollof, zobo]);

  @override
  Future<Product> product(String id) async =>
      const [jollof, zobo].firstWhere((p) => p.id == id);

  @override
  Future<Page<Review>> reviews(String slug, {String? cursor}) async => Page([
    Review(
      id: 'r-1',
      rating: 5,
      comment: 'Best jollof in Yaba',
      author: 'Tolu',
      createdAt: DateTime(2026, 11, 20),
    ),
  ]);

  @override
  Future<SearchResults> search(
    String query, {
    required double lat,
    required double lng,
  }) async {
    searches.add(query);
    return query.toLowerCase().contains('jol')
        ? const SearchResults(vendors: [mamaPut], products: [jollof])
        : const SearchResults();
  }

  @override
  Future<SearchResults> favourites() async => savedFavourites;

  @override
  Future<void> setFavourite({
    required bool vendor,
    required String id,
    required bool on,
  }) async => favouriteCalls.add(
    '${on ? '+' : '-'}${vendor ? 'vendor' : 'product'}:$id',
  );
}
