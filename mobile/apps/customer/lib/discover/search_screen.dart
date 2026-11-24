import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../catalogue/catalogue_providers.dart';
import '../catalogue/catalogue_repository.dart';
import '../catalogue/models.dart';
import '../catalogue/widgets.dart';
import '../l10n/generated/app_localizations.dart';
import '../location/delivery_location.dart';

/// One search across stores and products near the chosen location, with recent searches
/// (spec 09 "Search").
class SearchScreen extends ConsumerStatefulWidget {
  const SearchScreen({super.key});

  @override
  ConsumerState<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends ConsumerState<SearchScreen> {
  final _query = TextEditingController();
  Future<SearchResults>? _results;
  String _searched = '';

  @override
  void dispose() {
    _query.dispose();
    super.dispose();
  }

  Future<void> _search(String term) async {
    final t = term.trim();
    if (t.length < 2) return;
    _query.text = t;
    final location = await ref.read(deliveryLocationProvider.future);
    if (!mounted) return;
    if (location == null) {
      setState(
        () => _results = Future.error(
          const ApiException(ApiError(code: 'no_location', message: '')),
        ),
      );
      return;
    }
    unawaited(ref.read(recentSearchesProvider.notifier).add(t));
    setState(() {
      _searched = t;
      _results = ref
          .read(catalogueRepositoryProvider)
          .search(t, lat: location.lat, lng: location.lng);
    });
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final recent = ref.watch(recentSearchesProvider).value ?? const [];
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: TextField(
            controller: _query,
            autofocus: false,
            textInputAction: TextInputAction.search,
            decoration: InputDecoration(
              hintText: l10n.searchHint,
              border: InputBorder.none,
              prefixIcon: const Icon(Icons.search),
            ),
            onSubmitted: _search,
          ),
          bottom: _results == null
              ? null
              : TabBar(
                  tabs: [
                    Tab(text: l10n.storesTitle),
                    Tab(text: l10n.productsTitle),
                  ],
                ),
        ),
        body: _results == null
            ? ListView(
                children: [
                  if (recent.isNotEmpty)
                    ListTile(
                      title: Text(
                        l10n.recentSearches,
                        style: Theme.of(context).textTheme.titleSmall,
                      ),
                      trailing: TextButton(
                        onPressed: () =>
                            ref.read(recentSearchesProvider.notifier).clear(),
                        child: Text(l10n.clear),
                      ),
                    ),
                  for (final r in recent)
                    ListTile(
                      leading: const Icon(Icons.history),
                      title: Text(r),
                      onTap: () => _search(r),
                    ),
                  if (recent.isEmpty)
                    WgdStateView.empty(
                      icon: Icons.search,
                      title: l10n.searchEmptyTitle,
                      message: l10n.searchEmptyMessage,
                    ),
                ],
              )
            : FutureBuilder<SearchResults>(
                key: ValueKey(_searched),
                future: _results,
                builder: (context, snap) {
                  if (snap.connectionState != ConnectionState.done) {
                    return WgdStateView.loading(label: l10n.loading);
                  }
                  if (snap.hasError) {
                    final noLocation =
                        snap.error is ApiException &&
                        (snap.error! as ApiException).code == 'no_location';
                    return noLocation
                        ? WgdStateView.empty(
                            icon: Icons.location_off_outlined,
                            title: l10n.chooseLocation,
                            message: l10n.chooseLocationMessage,
                          )
                        : WgdStateView.error(
                            title: l10n.searchFailed,
                            retryLabel: l10n.retry,
                            onRetry: () => _search(_searched),
                          );
                  }
                  final results = snap.data!;
                  return TabBarView(
                    children: [
                      results.vendors.isEmpty
                          ? WgdStateView.empty(
                              icon: Icons.storefront_outlined,
                              title: l10n.noResults(_searched),
                            )
                          : ListView(
                              children: [
                                for (final v in results.vendors)
                                  VendorCard(
                                    vendor: v,
                                    onTap: () => context.push('/v/${v.slug}'),
                                  ),
                              ],
                            ),
                      results.products.isEmpty
                          ? WgdStateView.empty(
                              icon: Icons.fastfood_outlined,
                              title: l10n.noResults(_searched),
                            )
                          : ListView(
                              children: [
                                for (final p in results.products)
                                  ProductTile(
                                    product: p,
                                    showVendor: true,
                                    onTap: () => context.push('/p/${p.id}'),
                                  ),
                              ],
                            ),
                    ],
                  );
                },
              ),
      ),
    );
  }
}
