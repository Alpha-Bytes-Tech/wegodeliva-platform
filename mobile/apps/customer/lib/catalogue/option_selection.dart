import 'models.dart';

/// The options a customer picked for one product, checked against each group's rules
/// (spec 05 §5.1): at least `minimum`, at most `maxSelect`. With one choice allowed, picking
/// another replaces it. The price is (unit price + options) × quantity, as on the server.
class OptionSelection {
  OptionSelection(
    this.product, [
    Map<String, Set<String>>? chosen,
    this.quantity = 1,
  ]) : chosen = {
         for (final g in product.optionGroups) g.id: {...?chosen?[g.id]},
       };

  final Product product;
  final Map<String, Set<String>> chosen;
  final int quantity;

  bool isChosen(OptionGroup group, ProductOption option) =>
      chosen[group.id]!.contains(option.id);

  /// Picks or unpicks an option. Returns a new selection; a pick past the group's maximum
  /// is ignored, except in single-choice groups where it swaps.
  OptionSelection toggle(OptionGroup group, ProductOption option) {
    final next = {
      for (final e in chosen.entries) e.key: {...e.value},
    };
    final set = next[group.id]!;
    if (set.contains(option.id)) {
      set.remove(option.id);
    } else if (group.maxSelect <= 1) {
      set
        ..clear()
        ..add(option.id);
    } else if (set.length < group.maxSelect) {
      set.add(option.id);
    }
    return OptionSelection(product, next, quantity);
  }

  OptionSelection withQuantity(int q) =>
      OptionSelection(product, chosen, q.clamp(1, 50));

  /// Groups that still need a choice.
  List<OptionGroup> get missing => product.optionGroups
      .where((g) => chosen[g.id]!.length < g.minimum)
      .toList();

  bool get isValid => missing.isEmpty;

  int get optionsKobo => product.optionGroups
      .expand((g) => g.options.where((o) => chosen[g.id]!.contains(o.id)))
      .fold(0, (sum, o) => sum + o.price.kobo);

  int get totalKobo => (product.price.kobo + optionsKobo) * quantity;

  List<String> get optionIds => [
    for (final g in product.optionGroups) ...chosen[g.id]!,
  ];
}
