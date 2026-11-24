import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:wegodeliva_core/wegodeliva_core.dart';

/// Where the customer is shopping for: a saved address or their current position. Store
/// lists, delivery fees and quotes use it (spec 09 §9.1 "Welcome / location").
class DeliveryLocation {
  const DeliveryLocation({
    required this.label,
    required this.lat,
    required this.lng,
    this.addressId,
    this.detail,
  });

  factory DeliveryLocation.fromJson(Map<String, Object?> json) =>
      DeliveryLocation(
        label: json['label'].toString(),
        lat: (json['lat'] as num).toDouble(),
        lng: (json['lng'] as num).toDouble(),
        addressId: json['address_id'] as String?,
        detail: json['detail'] as String?,
      );

  factory DeliveryLocation.fromAddress(SavedAddress a) => DeliveryLocation(
    label: a.label ?? a.line1,
    lat: a.lat,
    lng: a.lng,
    addressId: a.id,
    detail: a.line1,
  );

  final String label;
  final double lat;
  final double lng;

  /// Set when it is a saved address (checkout can use it directly).
  final String? addressId;
  final String? detail;

  Map<String, Object?> toJson() => {
    'label': label,
    'lat': lat,
    'lng': lng,
    'address_id': addressId,
    'detail': detail,
  };
}

/// A saved address (`GET /me/addresses`).
class SavedAddress {
  const SavedAddress({
    required this.id,
    required this.line1,
    required this.lat,
    required this.lng,
    this.label,
    this.landmark,
    this.isDefault = false,
  });

  factory SavedAddress.fromJson(Map<String, Object?> json) => SavedAddress(
    id: json['id'].toString(),
    label: json['label'] as String?,
    line1: json['line1'].toString(),
    landmark: json['landmark'] as String?,
    lat: (json['lat'] as num).toDouble(),
    lng: (json['lng'] as num).toDouble(),
    isDefault: json['is_default'] == true,
  );

  final String id;
  final String? label;
  final String line1;
  final String? landmark;
  final double lat;
  final double lng;
  final bool isDefault;
}

/// The phone's position, or null when location is off or permission is refused.
abstract class LocationService {
  Future<({double lat, double lng})?> current();
}

class DeviceLocationService implements LocationService {
  @override
  Future<({double lat, double lng})?> current() async {
    if (!await Geolocator.isLocationServiceEnabled()) return null;
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      return null;
    }
    final position = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.medium,
        timeLimit: Duration(seconds: 15),
      ),
    );
    return (lat: position.latitude, lng: position.longitude);
  }
}

final locationServiceProvider = Provider<LocationService>(
  (ref) => DeviceLocationService(),
);

final savedAddressesProvider = FutureProvider<List<SavedAddress>>((ref) async {
  final data = await ref.watch(apiClientProvider).send('GET', '/me/addresses');
  return (data is List ? data : const [])
      .map((e) => SavedAddress.fromJson(Map<String, Object?>.from(e as Map)))
      .toList();
});

/// The chosen location, remembered between launches. Starts from the remembered one, else
/// the default saved address.
class DeliveryLocationController extends AsyncNotifier<DeliveryLocation?> {
  static const storeKey = 'delivery_location';

  @override
  Future<DeliveryLocation?> build() async {
    final raw = await ref.read(keyValueStoreProvider).read(storeKey);
    if (raw != null) {
      try {
        return DeliveryLocation.fromJson(
          Map<String, Object?>.from(jsonDecode(raw) as Map),
        );
      } on Object {
        await ref.read(keyValueStoreProvider).remove(storeKey);
      }
    }
    try {
      final addresses = await ref.read(savedAddressesProvider.future);
      final preferred = addresses
          .where((a) => a.isDefault)
          .followedBy(addresses)
          .firstOrNull;
      return preferred == null ? null : DeliveryLocation.fromAddress(preferred);
    } on ApiException {
      return null;
    }
  }

  Future<void> choose(DeliveryLocation location) async {
    state = AsyncData(location);
    await ref
        .read(keyValueStoreProvider)
        .write(storeKey, jsonEncode(location.toJson()));
  }

  /// Uses the phone's position. Returns false when location is off or refused.
  Future<bool> useCurrent(String label) async {
    final position = await ref.read(locationServiceProvider).current();
    if (position == null) return false;
    await choose(
      DeliveryLocation(label: label, lat: position.lat, lng: position.lng),
    );
    return true;
  }
}

final deliveryLocationProvider =
    AsyncNotifierProvider<DeliveryLocationController, DeliveryLocation?>(
      DeliveryLocationController.new,
    );
