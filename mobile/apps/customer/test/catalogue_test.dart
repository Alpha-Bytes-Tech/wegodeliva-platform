import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:customer_app/catalogue/catalogue_repository.dart';
import 'package:customer_app/catalogue/models.dart';
import 'package:customer_app/catalogue/option_selection.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

import 'support/fake_catalogue.dart';

class Adapter implements HttpClientAdapter {
  Adapter(this.handler);

  final Object? Function(RequestOptions o) handler;
  final requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    final body = handler(options);
    if (body is DioException) throw body;
    if (body == null) return ResponseBody.fromString('', 204);
    return ResponseBody.fromString(
      jsonEncode(body),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

CatalogueRepository repositoryWith(Adapter adapter, [KeyValueStore? store]) =>
    CatalogueRepository(
      ApiClient(
        config: AppConfig.forFlavor(
          flavor: Flavor.staging,
          app: ClientApp.customer,
          appVersion: '1.0.0',
        ),
        tokenStorage: InMemoryTokenStorage(),
        dio: Dio()..httpClientAdapter = adapter,
        logger: AppLogger(enabled: false),
      ),
      store ?? InMemoryKeyValueStore(),
    );

const vendorJson = {
  'id': 'v-1',
  'slug': 'mama-put',
  'name': 'Mama Put',
  'vendor_type': 'food',
  'open_state': 'busy',
  'logo_url': 'https://cdn/logo',
  'rating': {'average': 4.5, 'count': 10},
  'distance_m': 900,
  'deliverable': true,
  'min_order': {'amount_kobo': 100000, 'formatted': '₦1,000.00'},
  'is_featured': true,
  'allows_scheduled_orders': true,
  'hours': [
    {'weekday': 1, 'opens_at': '08:00', 'closes_at': '22:00'},
  ],
  'menus': [
    {'id': 'm-1', 'name': 'Rice'},
  ],
};

void main() {
  test('formats naira amounts', () {
    expect(Money.ofKobo(150000).formatted, '₦1,500');
    expect(Money.ofKobo(123456789).formatted, '₦1,234,567.89');
    expect(Money.ofKobo(-5050).formatted, '-₦50.50');
    expect(Money.ofKobo(7).toString(), '₦0.07');
  });

  test('applies option rules and prices like the server', () {
    final s0 = OptionSelection(jollof);
    final protein = jollof.optionGroups[0];
    final extras = jollof.optionGroups[1];
    expect(s0.isValid, isFalse);
    expect(s0.missing.single.name, 'Protein');
    expect(protein.minimum, 1);
    expect(extras.minimum, 0);

    var s = s0
        .toggle(protein, protein.options[0])
        .toggle(extras, extras.options[0])
        .toggle(extras, extras.options[1]);
    expect(s.isValid, isTrue);
    expect(s.optionsKobo, 80000);
    // A third extra is ignored; another protein replaces the first.
    s = s.toggle(extras, extras.options[2]).toggle(protein, protein.options[1]);
    expect(s.optionIds, ['o-beef', 'o-plantain', 'o-egg']);
    expect(s.withQuantity(3).totalKobo, (150000 + 30000 + 20000 + 10000) * 3);
    expect(s.withQuantity(99).quantity, 50);
    expect(s.withQuantity(0).quantity, 1);
    // Unpicking.
    expect(
      s.toggle(extras, extras.options[0]).isChosen(extras, extras.options[0]),
      isFalse,
    );
  });

  test(
    'reads stores, products, reviews and search results, with pages',
    () async {
      final adapter = Adapter(
        (o) => switch (o.uri.path) {
          '/api/v1/catalogue/vendors' => {
            'data': [vendorJson],
            'links': {'next': 'https://x?page=2'},
          },
          '/api/v1/catalogue/vendors/mama-put' => {'data': vendorJson},
          '/api/v1/catalogue/vendors/mama-put/products' => {
            'data': [
              {
                'id': 'p-1',
                'name': 'Rice',
                'price': {'amount_kobo': 1500, 'formatted': '₦15'},
                'original_price': {'amount_kobo': 2000, 'formatted': '₦20'},
                'image_urls': ['https://cdn/a'],
                'in_stock': false,
                'service': {'pricing_unit': 'hour'},
                'option_groups': [
                  {
                    'id': 'g',
                    'name': 'G',
                    'is_required': true,
                    'min_select': 0,
                    'max_select': 2,
                    'options': [
                      {
                        'id': 'o',
                        'name': 'O',
                        'price': {'amount_kobo': 100, 'formatted': '₦1'},
                        'per_unit': false,
                      },
                    ],
                  },
                ],
              },
            ],
            'meta': {'next_cursor': 'c2'},
          },
          '/api/v1/catalogue/vendors/mama-put/reviews' => {
            'data': [
              {
                'id': 'r',
                'rating': 4,
                'comment': 'Good',
                'author': 'Ada',
                'created_at': '2026-11-20T10:00:00+00:00',
              },
            ],
            'meta': {},
          },
          '/api/v1/catalogue/vendors/mama-put/delivery-quote' => {
            'data': {
              'deliverable': true,
              'fee': {'amount_kobo': 50000, 'formatted': '₦500'},
              'eta_minutes': {'min': 30, 'max': 40},
            },
          },
          '/api/v1/catalogue/search' => {
            'data': {
              'vendors': [vendorJson],
              'products': [],
            },
          },
          '/api/v1/catalogue/vendor-types' => {
            'data': [
              {'slug': 'food', 'name': 'Food'},
            ],
          },
          '/api/v1/catalogue/vendor-types/food/categories' => {
            'data': [
              {'slug': 'rice', 'name': 'Rice'},
            ],
          },
          _ => {
            'data': {'vendors': [], 'products': []},
          },
        },
      );
      final repo = repositoryWith(adapter);

      final page = await repo.vendors(
        lat: 6.5,
        lng: 3.4,
        type: 'food',
        query: 'mama',
      );
      expect(page.next, '2');
      final v = page.items.single;
      expect(
        [
          v.openState,
          v.isOpen,
          v.featured,
          v.minOrder.kobo,
          v.distanceM,
          v.menus.single.name,
          v.hours.single.closes,
        ],
        [OpenState.busy, false, true, 100000, 900, 'Rice', '22:00'],
      );
      expect(adapter.requests.first.uri.queryParameters, {
        'lat': '6.5',
        'lng': '3.4',
        'vendor_type': 'food',
        'q': 'mama',
        'page': '1',
        'per_page': '20',
      });

      expect(
        (await repo.vendor('mama-put', lat: 6.5, lng: 3.4)).logoUrl,
        'https://cdn/logo',
      );
      final products = await repo.products('mama-put', menu: 'm-1');
      final p = products.items.single;
      expect(
        [
          products.next,
          p.inStock,
          p.pricingUnit,
          p.originalPrice?.kobo,
          p.imageUrls.single,
        ],
        ['c2', false, 'hour', 2000, 'https://cdn/a'],
      );
      expect(p.optionGroups.single.minimum, 1);
      expect(p.optionGroups.single.options.single.perUnit, isFalse);
      expect(
        (await repo.reviews('mama-put')).items.single.createdAt.year,
        2026,
      );
      final quote = await repo.deliveryQuote('mama-put', lat: 6.5, lng: 3.4);
      expect(
        [quote.deliverable, quote.fee?.formatted, quote.etaMax],
        [true, '₦500', 40],
      );
      expect(
        (await repo.search('mama', lat: 1, lng: 2)).vendors.single.slug,
        'mama-put',
      );
      expect((await repo.categories('food')).single.name, 'Rice');
      expect((await repo.favourites()).isEmpty, isTrue);
    },
  );

  test(
    'keeps the first page of nearby stores and store types for offline use',
    () async {
      final store = InMemoryKeyValueStore();
      final online = repositoryWith(
        Adapter(
          (o) => {
            'data': o.uri.path.endsWith('vendor-types')
                ? [
                    {'slug': 'food', 'name': 'Food'},
                  ]
                : [vendorJson],
            'links': {},
          },
        ),
        store,
      );
      await online.vendorTypes();
      await online.vendors(lat: 1, lng: 2);

      final offline = repositoryWith(
        Adapter(
          (o) => DioException(
            requestOptions: o,
            error: const SocketException('off'),
            type: DioExceptionType.connectionError,
          ),
        ),
        store,
      );
      expect((await offline.vendorTypes()).single.slug, 'food');
      expect(
        (await offline.vendors(lat: 1, lng: 2)).items.single.name,
        'Mama Put',
      );
      // Filtered lists are never served from the cache.
      await expectLater(
        offline.vendors(lat: 1, lng: 2, type: 'food'),
        throwsA(isA<ApiException>()),
      );
      await expectLater(
        repositoryWith(
          Adapter(
            (o) => DioException(
              requestOptions: o,
              type: DioExceptionType.connectionError,
            ),
          ),
        ).vendorTypes(),
        throwsA(isA<ApiException>()),
      );
    },
  );

  test('saves and removes favourites', () async {
    final adapter = Adapter((_) => null);
    final repo = repositoryWith(adapter);
    await repo.setFavourite(vendor: true, id: 'v-1', on: true);
    await repo.setFavourite(vendor: false, id: 'p-1', on: false);
    expect(adapter.requests.map((r) => '${r.method} ${r.uri.path}'), [
      'PUT /api/v1/me/favourites/vendors/v-1',
      'DELETE /api/v1/me/favourites/products/p-1',
    ]);
  });
}
