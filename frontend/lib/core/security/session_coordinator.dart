import 'dart:async';

import 'secure_session_store.dart';

class SessionInvalidation {
  final int generation;

  const SessionInvalidation(this.generation);
}

class SessionCoordinator {
  final SessionStore _store;
  final _invalidations = StreamController<SessionInvalidation>.broadcast();
  final Map<int, Future<bool>> _inFlight = {};

  SessionCoordinator(this._store);

  SessionSnapshot get snapshot => _store.snapshot;
  Stream<SessionInvalidation> get invalidations => _invalidations.stream;

  Future<bool> invalidateIfCurrent(SessionSnapshot requestSnapshot) {
    final existing = _inFlight[requestSnapshot.generation];
    if (existing != null) return existing;

    final operation = _invalidate(requestSnapshot);
    _inFlight[requestSnapshot.generation] = operation;
    operation.then<void>(
      (_) => _removeInFlight(requestSnapshot, operation),
      onError: (_, _) => _removeInFlight(requestSnapshot, operation),
    );
    return operation;
  }

  void _removeInFlight(
    SessionSnapshot requestSnapshot,
    Future<bool> operation,
  ) {
    if (identical(_inFlight[requestSnapshot.generation], operation)) {
      _inFlight.remove(requestSnapshot.generation);
    }
  }

  Future<bool> _invalidate(SessionSnapshot requestSnapshot) async {
    if (!requestSnapshot.hasToken) return false;
    try {
      final cleared = await _store.clearIfMatches(requestSnapshot);
      if (cleared) _emit(requestSnapshot);
      return cleared;
    } catch (_) {
      final current = _store.snapshot;
      if (!current.hasToken &&
          current.generation != requestSnapshot.generation) {
        _emit(requestSnapshot);
      }
      rethrow;
    }
  }

  void _emit(SessionSnapshot requestSnapshot) {
    if (!_invalidations.isClosed) {
      _invalidations.add(SessionInvalidation(requestSnapshot.generation));
    }
  }

  Future<void> close() => _invalidations.close();
}
