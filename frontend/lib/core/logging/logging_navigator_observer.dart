import 'package:flutter/widgets.dart';

import 'app_logger.dart';

/// Mencatat perpindahan halaman.
///
/// Berguna saat membaca laporan error: baris rute terakhir sebelum sebuah
/// exception memberi tahu layar mana yang sedang dibuka user ketika gagal.
class LoggingNavigatorObserver extends NavigatorObserver {
  final AppLogger _log = AppLogger.tag('Route');

  String _name(Route<dynamic>? route) =>
      route?.settings.name ?? route?.runtimeType.toString() ?? '<null>';

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) {
    super.didPush(route, previousRoute);
    _log.info('push', data: {'ke': _name(route), 'dari': _name(previousRoute)});
  }

  @override
  void didPop(Route<dynamic> route, Route<dynamic>? previousRoute) {
    super.didPop(route, previousRoute);
    _log.info('pop', data: {'dari': _name(route), 'ke': _name(previousRoute)});
  }

  @override
  void didReplace({Route<dynamic>? newRoute, Route<dynamic>? oldRoute}) {
    super.didReplace(newRoute: newRoute, oldRoute: oldRoute);
    _log.info(
      'replace',
      data: {'lama': _name(oldRoute), 'baru': _name(newRoute)},
    );
  }

  @override
  void didRemove(Route<dynamic> route, Route<dynamic>? previousRoute) {
    super.didRemove(route, previousRoute);
    _log.debug(
      'remove',
      data: {'dihapus': _name(route), 'sebelum': _name(previousRoute)},
    );
  }
}
