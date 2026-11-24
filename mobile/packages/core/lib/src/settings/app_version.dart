/// Compares dotted versions such as `1.2.10` numerically; missing parts count as 0 and any
/// pre-release or build suffix (`-beta`, `+3`) is ignored.
int compareVersions(String a, String b) {
  List<int> parts(String v) => v
      .split(RegExp(r'[-+]'))
      .first
      .split('.')
      .map((p) => int.tryParse(p.trim()) ?? 0)
      .toList();
  final x = parts(a);
  final y = parts(b);
  final length = x.length > y.length ? x.length : y.length;
  for (var i = 0; i < length; i++) {
    final left = i < x.length ? x[i] : 0;
    final right = i < y.length ? y[i] : 0;
    if (left != right) return left < right ? -1 : 1;
  }
  return 0;
}
