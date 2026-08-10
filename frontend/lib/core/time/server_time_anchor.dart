class ServerTimeAnchor {
  late final Duration Function() _ticks;
  DateTime? _serverTime;
  Duration? _anchoredAt;

  ServerTimeAnchor({Duration Function()? ticks, Stopwatch? stopwatch}) {
    final clock = stopwatch ?? (Stopwatch()..start());
    _ticks = ticks ?? (() => clock.elapsed);
  }

  bool get isAvailable => _serverTime != null && _anchoredAt != null;
  Duration get monotonicNow => _ticks();

  DateTime? get now {
    final serverTime = _serverTime;
    final anchoredAt = _anchoredAt;
    if (serverTime == null || anchoredAt == null) return null;
    final elapsed = _ticks() - anchoredAt;
    if (elapsed.isNegative) return null;
    return serverTime.add(elapsed);
  }

  bool anchorFromIso(Object? value) {
    if (value is! String) return false;
    final parsed = DateTime.tryParse(value);
    if (parsed == null) return false;
    _serverTime = parsed.toUtc();
    _anchoredAt = _ticks();
    return true;
  }

  void clear() {
    _serverTime = null;
    _anchoredAt = null;
  }
}
