import 'app_version.dart';

/// What the API tells an app at start-up (`GET /app/settings`): versions, feature flags,
/// payment methods, limits, support contacts, legal pages and onboarding slides.
class AppSettings {
  const AppSettings({
    required this.minVersion,
    required this.latestVersion,
    this.storeUrls = const {},
    this.features = const {},
    this.paymentMethods = const {},
    this.support = const SupportContacts(),
    this.supportCategories = const [],
    this.pages = const [],
    this.onboarding = const [],
  });

  factory AppSettings.fromJson(Map<String, Object?> json) {
    final app = _map(json['app']);
    return AppSettings(
      minVersion: app['min_version']?.toString() ?? '0.0.0',
      latestVersion: app['latest_version']?.toString() ?? '0.0.0',
      storeUrls: _map(
        app['store_urls'],
      ).map((k, v) => MapEntry(k, v?.toString())),
      features: _map(json['features']).map((k, v) => MapEntry(k, v == true)),
      paymentMethods: _map(
        json['payment_methods'],
      ).map((k, v) => MapEntry(k, v == true)),
      support: SupportContacts.fromJson(_map(json['support'])),
      supportCategories: _list(
        json['support_categories'],
      ).map((e) => e.toString()).toList(),
      pages: _list(
        json['pages'],
      ).map((p) => PageLink.fromJson(_map(p))).toList(),
      onboarding: _list(
        json['onboarding'],
      ).map((s) => Slide.fromJson(_map(s))).toList(),
    );
  }

  final String minVersion;
  final String latestVersion;
  final Map<String, String?> storeUrls;
  final Map<String, bool> features;
  final Map<String, bool> paymentMethods;
  final SupportContacts support;
  final List<String> supportCategories;
  final List<PageLink> pages;
  final List<Slide> onboarding;

  /// The installed version is below the minimum: the app must be updated before use.
  bool mustUpdate(String installed) =>
      compareVersions(installed, minVersion) < 0;

  /// A newer version exists but this one still works.
  bool canUpdate(String installed) =>
      compareVersions(installed, latestVersion) < 0;

  bool feature(String name) => features[name] ?? false;

  static Map<String, Object?> _map(Object? v) =>
      v is Map ? Map<String, Object?>.from(v) : const {};

  static List<Object?> _list(Object? v) => v is List ? v : const [];
}

class SupportContacts {
  const SupportContacts({this.whatsapp, this.email, this.phone});

  factory SupportContacts.fromJson(Map<String, Object?> json) =>
      SupportContacts(
        whatsapp: json['whatsapp'] as String?,
        email: json['email'] as String?,
        phone: json['phone'] as String?,
      );

  final String? whatsapp;
  final String? email;
  final String? phone;
}

class PageLink {
  const PageLink({required this.slug, required this.title});

  factory PageLink.fromJson(Map<String, Object?> json) =>
      PageLink(slug: json['slug'].toString(), title: json['title'].toString());

  final String slug;
  final String title;
}

/// An onboarding slide or banner.
class Slide {
  const Slide({required this.title, this.subtitle, this.imageUrl, this.link});

  factory Slide.fromJson(Map<String, Object?> json) => Slide(
    title: json['title'].toString(),
    subtitle: json['subtitle'] as String?,
    imageUrl: json['image_url'] as String?,
    link: json['link'] as String?,
  );

  final String title;
  final String? subtitle;
  final String? imageUrl;
  final String? link;
}
