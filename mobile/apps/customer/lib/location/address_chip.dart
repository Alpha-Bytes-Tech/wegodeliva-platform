import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wegodeliva_ui/wegodeliva_ui.dart';

import '../l10n/generated/app_localizations.dart';
import 'delivery_location.dart';

/// "Delivering to …" chip at the top of Home; opens the location picker (spec 09 "Home").
class AddressChip extends ConsumerWidget {
  const AddressChip({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final location = ref.watch(deliveryLocationProvider).value;
    final label = location?.label ?? l10n.chooseLocation;
    return Semantics(
      button: true,
      label: l10n.deliveringTo(label),
      excludeSemantics: true,
      child: InkWell(
        borderRadius: BorderRadius.circular(WgdSizes.radius),
        onTap: () => showLocationPicker(context),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: WgdSizes.minTapTarget),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.location_on_outlined,
                color: Theme.of(context).colorScheme.primary,
              ),
              const SizedBox(width: WgdSpacing.xs),
              Flexible(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      l10n.deliveringToLabel,
                      style: Theme.of(context).textTheme.labelSmall,
                    ),
                    Text(
                      label,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.titleSmall,
                    ),
                  ],
                ),
              ),
              const Icon(Icons.expand_more),
            ],
          ),
        ),
      ),
    );
  }
}

Future<void> showLocationPicker(BuildContext context) =>
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (_) => const LocationPicker(),
    );

/// Saved addresses plus "use my current location". Adding addresses (with pin-drop) comes
/// with checkout (3.4).
class LocationPicker extends ConsumerStatefulWidget {
  const LocationPicker({super.key});

  @override
  ConsumerState<LocationPicker> createState() => _LocationPickerState();
}

class _LocationPickerState extends ConsumerState<LocationPicker> {
  bool _locating = false;
  String? _error;

  Future<void> _useCurrent() async {
    final l10n = AppLocalizations.of(context);
    setState(() {
      _locating = true;
      _error = null;
    });
    final ok = await ref
        .read(deliveryLocationProvider.notifier)
        .useCurrent(l10n.currentLocation);
    if (!mounted) return;
    if (ok) {
      Navigator.of(context).pop();
    } else {
      setState(() {
        _locating = false;
        _error = l10n.locationUnavailable;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final addresses = ref.watch(savedAddressesProvider);
    final selected = ref.watch(deliveryLocationProvider).value;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(
          WgdSpacing.md,
          0,
          WgdSpacing.md,
          WgdSpacing.md,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              l10n.chooseLocation,
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: WgdSpacing.sm),
            ListTile(
              leading: _locating
                  ? const SizedBox.square(
                      dimension: 24,
                      child: CircularProgressIndicator(strokeWidth: 2.5),
                    )
                  : const Icon(Icons.my_location),
              title: Text(l10n.useCurrentLocation),
              onTap: _locating ? null : _useCurrent,
            ),
            if (_error != null) WgdStatusMessage.error(_error!),
            const Divider(),
            addresses.when(
              loading: () => Padding(
                padding: const EdgeInsets.all(WgdSpacing.lg),
                child: Center(
                  child: CircularProgressIndicator(
                    semanticsLabel: l10n.loading,
                  ),
                ),
              ),
              error: (_, _) => ListTile(
                leading: const Icon(Icons.cloud_off_outlined),
                title: Text(l10n.addressesFailed),
                trailing: TextButton(
                  onPressed: () => ref.invalidate(savedAddressesProvider),
                  child: Text(l10n.retry),
                ),
              ),
              data: (list) => list.isEmpty
                  ? ListTile(
                      leading: const Icon(Icons.bookmark_border),
                      title: Text(l10n.noSavedAddresses),
                    )
                  : Column(
                      children: [
                        for (final a in list)
                          ListTile(
                            leading: Icon(
                              a.id == selected?.addressId
                                  ? Icons.check_circle
                                  : Icons.place_outlined,
                            ),
                            title: Text(a.label ?? a.line1),
                            subtitle: Text(
                              [
                                a.line1,
                                a.landmark,
                              ].whereType<String>().join(' · '),
                            ),
                            onTap: () async {
                              await ref
                                  .read(deliveryLocationProvider.notifier)
                                  .choose(DeliveryLocation.fromAddress(a));
                              if (context.mounted) Navigator.of(context).pop();
                            },
                          ),
                      ],
                    ),
            ),
          ],
        ),
      ),
    );
  }
}
