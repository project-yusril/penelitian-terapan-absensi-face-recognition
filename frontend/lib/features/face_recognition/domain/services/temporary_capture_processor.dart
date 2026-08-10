import 'dart:io';
import 'dart:typed_data';

import 'package:shared_preferences/shared_preferences.dart';

typedef CaptureFileDeleter = Future<void> Function(String path);

final class TemporaryCaptureCleanupRegistry {
  static const String _storageKey = 'owned_capture_cleanup_v1';
  static const Duration maximumRetention = Duration(hours: 1);
  static const int maximumEntries = 32;

  final SharedPreferences _preferences;
  final CaptureFileDeleter _delete;
  final DateTime Function() _now;

  TemporaryCaptureCleanupRegistry(
    this._preferences, {
    CaptureFileDeleter? delete,
    DateTime Function()? now,
  }) : _delete = delete ?? TemporaryCaptureProcessor._deleteFile,
       _now = now ?? DateTime.now;

  Future<void> enqueue(String path) async {
    final entries = _entries()
      ..removeWhere((entry) => entry.path == path)
      ..add(_CleanupEntry(path, _now().toUtc()));
    if (entries.length > maximumEntries) {
      entries.removeRange(0, entries.length - maximumEntries);
    }
    await _save(entries);
  }

  Future<void> remove(String path) async {
    final entries = _entries()..removeWhere((entry) => entry.path == path);
    await _save(entries);
  }

  Future<void> retryCleanup() async {
    final now = _now().toUtc();
    final retained = <_CleanupEntry>[];
    for (final entry in _entries()) {
      if (now.difference(entry.createdAt) > maximumRetention) continue;
      try {
        await _delete(entry.path);
      } catch (_) {
        retained.add(entry);
      }
    }
    await _save(retained);
  }

  List<_CleanupEntry> _entries() {
    final values = _preferences.getStringList(_storageKey) ?? const [];
    final entries = <_CleanupEntry>[];
    for (final value in values) {
      final separator = value.indexOf('|');
      if (separator <= 0) continue;
      final createdAt = DateTime.tryParse(value.substring(0, separator));
      final path = value.substring(separator + 1);
      if (createdAt != null && path.isNotEmpty) {
        entries.add(_CleanupEntry(path, createdAt.toUtc()));
      }
    }
    return entries;
  }

  Future<void> _save(List<_CleanupEntry> entries) => _preferences.setStringList(
    _storageKey,
    entries
        .map((entry) => '${entry.createdAt.toIso8601String()}|${entry.path}')
        .toList(growable: false),
  );
}

final class _CleanupEntry {
  final String path;
  final DateTime createdAt;

  const _CleanupEntry(this.path, this.createdAt);
}

final class TemporaryCapture {
  final String path;
  final Uint8List bytes;
  final int attemptId;
  final int captureId;

  const TemporaryCapture({
    required this.path,
    required this.bytes,
    required this.attemptId,
    required this.captureId,
  });
}

/// Owns a `takePicture` file only for one operation. Retention is immediate
/// deletion; there is no disk retry and no broad temporary-directory sweep.
final class TemporaryCaptureProcessor {
  final CaptureFileDeleter _delete;
  final TemporaryCaptureCleanupRegistry? _registry;

  TemporaryCaptureProcessor({
    CaptureFileDeleter? delete,
    TemporaryCaptureCleanupRegistry? registry,
  }) : _delete = delete ?? _deleteFile,
       _registry = registry;

  Future<void> retryCleanup() async => _registry?.retryCleanup();

  Future<T> process<T>({
    required String path,
    required int attemptId,
    required int captureId,
    required Future<T> Function(TemporaryCapture capture) operation,
  }) async {
    Object? operationError;
    StackTrace? operationStack;
    T? result;
    try {
      await _registry?.enqueue(path);
    } catch (_) {}
    try {
      final bytes = await File(path).readAsBytes();
      result = await operation(
        TemporaryCapture(
          path: path,
          bytes: bytes,
          attemptId: attemptId,
          captureId: captureId,
        ),
      );
    } catch (error, stackTrace) {
      operationError = error;
      operationStack = stackTrace;
    }

    try {
      await _delete(path);
      try {
        await _registry?.remove(path);
      } catch (_) {}
    } catch (deleteError, deleteStack) {
      try {
        await _registry?.enqueue(path);
      } catch (_) {}
      if (operationError == null) {
        Error.throwWithStackTrace(deleteError, deleteStack);
      }
    }
    if (operationError != null) {
      Error.throwWithStackTrace(operationError, operationStack!);
    }
    return result as T;
  }

  static Future<void> _deleteFile(String path) async {
    final file = File(path);
    if (await file.exists()) await file.delete();
  }
}
