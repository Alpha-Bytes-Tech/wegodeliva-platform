/// Build flavours. Each app has `main_dev.dart`, `main_staging.dart` and `main_prod.dart`.
enum Flavor {
  dev,
  staging,
  prod;

  bool get isProduction => this == Flavor.prod;
}
