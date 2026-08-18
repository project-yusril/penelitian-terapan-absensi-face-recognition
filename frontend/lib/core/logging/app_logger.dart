import 'dart:collection';
import 'dart:convert';
import 'dart:developer' as developer;
import 'dart:math' as math;

import 'package:flutter/foundation.dart';

/// Tingkat kepentingan log, terurut dari paling berisik ke paling kritis.
enum LogLevel {
  trace(0, 'TRACE'),
  debug(1, 'DEBUG'),
  info(2, 'INFO'),
  warn(3, 'WARN'),
  error(4, 'ERROR');

  const LogLevel(this.severity, this.label);

  final int severity;
  final String label;
}

/// Satu baris log yang disimpan di buffer memori.
class LogRecord {
  final DateTime timestamp;
  final LogLevel level;
  final String tag;
  final String message;
  final Map<String, Object?>? data;
  final Object? error;
  final StackTrace? stackTrace;

  const LogRecord({
    required this.timestamp,
    required this.level,
    required this.tag,
    required this.message,
    this.data,
    this.error,
    this.stackTrace,
  });

  String format({bool includeStackTrace = false}) {
    final time = timestamp.toIso8601String().substring(11, 23);
    final buffer = StringBuffer('$time ${level.label.padRight(5)} [$tag] $message');
    if (data != null && data!.isNotEmpty) {
      buffer.write(' ${_encodeData(data!)}');
    }
    if (error != null) {
      buffer.write('\n    ERROR: ${error.runtimeType}: $error');
    }
    if (includeStackTrace && stackTrace != null) {
      buffer.write('\n${_indentStackTrace(stackTrace!)}');
    }
    return buffer.toString();
  }

  @override
  String toString() => format();
}

/// Logger aplikasi.
///
/// Tiga alasan memakai ini alih-alih `debugPrint` langsung:
///
/// 1. **Redaksi.** Aplikasi ini memproses embedding biometrik, token Sanctum,
///    dan password. Nilai mentahnya tidak boleh pernah masuk logcat — logcat
///    bisa dibaca aplikasi lain di perangkat yang sudah di-root dan ikut
///    terbawa saat bug report. [redact] meringkas alih-alih membocorkan.
/// 2. **Buffer.** Log terakhir disimpan di memori sehingga bisa diekspor lewat
///    [export] saat user melaporkan masalah, tanpa perlu kabel USB.
/// 3. **Konsistensi.** Semua baris punya waktu, level, dan tag yang seragam
///    sehingga bisa difilter: `adb logcat | grep "\[Enrollment\]"`.
class AppLogger {
  AppLogger._(this.tag);

  final String tag;

  static final Map<String, AppLogger> _instances = <String, AppLogger>{};

  /// Ambil (atau buat) logger untuk sebuah tag. Tag dipakai sebagai filter.
  factory AppLogger.tag(String tag) =>
      _instances.putIfAbsent(tag, () => AppLogger._(tag));

  /// Batas level yang dicetak. Di release default-nya [LogLevel.warn] supaya
  /// build produksi tidak membocorkan alur kerja lewat logcat.
  static LogLevel minimumLevel = kReleaseMode ? LogLevel.warn : LogLevel.trace;

  /// Jumlah baris yang ditahan di memori untuk [export].
  static const int bufferCapacity = 500;

  static final Queue<LogRecord> _buffer = Queue<LogRecord>();

  /// Salinan log yang masih tersimpan, terlama lebih dulu.
  static List<LogRecord> get records => List.unmodifiable(_buffer);

  /// Gabungkan buffer jadi satu teks siap kirim/tempel ke laporan bug.
  static String export({bool includeStackTraces = true}) => _buffer
      .map((record) => record.format(includeStackTrace: includeStackTraces))
      .join('\n');

  static void clear() => _buffer.clear();

  void trace(String message, {Map<String, Object?>? data}) =>
      _write(LogLevel.trace, message, data: data);

  void debug(String message, {Map<String, Object?>? data}) =>
      _write(LogLevel.debug, message, data: data);

  void info(String message, {Map<String, Object?>? data}) =>
      _write(LogLevel.info, message, data: data);

  void warn(
    String message, {
    Map<String, Object?>? data,
    Object? error,
    StackTrace? stackTrace,
  }) =>
      _write(
        LogLevel.warn,
        message,
        data: data,
        error: error,
        stackTrace: stackTrace,
      );

  void error(
    String message, {
    Object? error,
    StackTrace? stackTrace,
    Map<String, Object?>? data,
  }) =>
      _write(
        LogLevel.error,
        message,
        data: data,
        error: error,
        stackTrace: stackTrace,
      );

  /// Jalankan [action] sambil mencatat mulai, durasi, dan hasil/kegagalannya.
  ///
  /// Exception tetap dilempar ulang — helper ini mengamati, bukan menelan.
  /// Inilah cara memberi log pada sebuah fungsi tanpa menulis try/catch manual
  /// di setiap pemanggil:
  ///
  /// ```dart
  /// final dup = await _log.timed('checkDuplicate', () => bloc.checkDuplicate(e));
  /// ```
  Future<T> timed<T>(
    String label,
    Future<T> Function() action, {
    Map<String, Object?>? data,
    Object? Function(T value)? describeResult,
  }) async {
    final stopwatch = Stopwatch()..start();
    _write(LogLevel.debug, '$label → mulai', data: data);
    try {
      final result = await action();
      stopwatch.stop();
      _write(
        LogLevel.info,
        '$label ✓ selesai',
        data: <String, Object?>{
          'ms': stopwatch.elapsedMilliseconds,
          if (describeResult != null) 'hasil': describeResult(result),
        },
      );
      return result;
    } catch (err, stack) {
      stopwatch.stop();
      _write(
        LogLevel.error,
        '$label ✗ gagal',
        data: <String, Object?>{'ms': stopwatch.elapsedMilliseconds, ...?data},
        error: err,
        stackTrace: stack,
      );
      rethrow;
    }
  }

  void _write(
    LogLevel level,
    String message, {
    Map<String, Object?>? data,
    Object? error,
    StackTrace? stackTrace,
  }) {
    final record = LogRecord(
      timestamp: DateTime.now(),
      level: level,
      tag: tag,
      message: message,
      data: data == null ? null : redactMap(data),
      error: error,
      stackTrace: stackTrace,
    );

    _buffer.addLast(record);
    while (_buffer.length > bufferCapacity) {
      _buffer.removeFirst();
    }

    if (level.severity < minimumLevel.severity) return;

    // debugPrint melakukan throttling sehingga baris panjang tidak dipotong
    // oleh buffer logcat Android.
    debugPrint(record.format());
    if (stackTrace != null) {
      debugPrint(_indentStackTrace(stackTrace));
    }
    // Menyalurkan juga ke dart:developer agar terlihat rapi di DevTools.
    developer.log(
      record.format(),
      name: tag,
      level: _developerLevel(level),
      error: error,
      stackTrace: stackTrace,
    );
  }

  static int _developerLevel(LogLevel level) => switch (level) {
        LogLevel.trace => 300,
        LogLevel.debug => 500,
        LogLevel.info => 800,
        LogLevel.warn => 900,
        LogLevel.error => 1000,
      };
}

/// Kunci yang isinya tidak boleh muncul utuh di log.
const Set<String> _sensitiveKeys = <String>{
  'password',
  'password_confirmation',
  'current_password',
  'new_password',
  'token',
  'access_token',
  'refresh_token',
  'authorization',
  'bearer',
  'secret',
  'embedding',
  'embedding[]',
  'face_embedding',
  'matched_name',
  'foto',
  'photo',
  'signature',
  'cookie',
  'set-cookie',
};

bool _isSensitiveKey(String key) {
  final lower = key.toLowerCase();
  return _sensitiveKeys.any((needle) => lower.contains(needle));
}

/// Ganti nilai sensitif dengan ringkasan yang tetap berguna untuk debugging.
///
/// Tujuannya tetap bisa menjawab "embedding-nya kebentuk nggak, panjangnya
/// benar nggak" tanpa pernah menuliskan angkanya — vektor biometrik utuh
/// setara data wajah dan tidak boleh masuk logcat.
Map<String, Object?> redactMap(Map<String, Object?> input) {
  return input.map((key, value) {
    if (_isSensitiveKey(key)) return MapEntry(key, redact(value));
    return MapEntry(key, _shallow(value));
  });
}

/// Ringkas satu nilai sensitif.
Object? redact(Object? value) {
  if (value == null) return null;

  // Skalar bukan rahasianya: di bawah kunci sensitif, sebuah angka hampir
  // selalu berupa panjang atau jumlah ("panjangEmbedding": 192) — justru
  // informasi yang paling dibutuhkan saat menelusuri penolakan `size:192`.
  // Menyensornya membuat log kehilangan gunanya tanpa menambah keamanan.
  if (value is num || value is bool) return value;

  // Nilai yang sudah berupa ringkasan (mis. keluaran describeVector) tidak
  // perlu disensor ulang; menyensornya justru membuang hasil peringkasan.
  if (value is String && value.startsWith('<') && value.endsWith('>')) {
    return value;
  }

  if (value is List) {
    if (value.isEmpty) return '<kosong len=0>';
    if (value.first is num) return describeVector(value.cast<num>());
    return '<list len=${value.length}>';
  }
  if (value is String) return '<redacted len=${value.length}>';
  if (value is Uint8List) return '<bytes len=${value.length}>';
  return '<redacted ${value.runtimeType}>';
}

/// Ringkasan statistik sebuah vektor: cukup untuk mendeteksi vektor rusak
/// (panjang salah, semua nol, ada NaN) tanpa mengungkap isinya.
String describeVector(List<num> vector) {
  if (vector.isEmpty) return '<vector len=0>';
  var min = double.infinity;
  var max = double.negativeInfinity;
  var sum = 0.0;
  var sumSquares = 0.0;
  var nonFinite = 0;
  for (final raw in vector) {
    final value = raw.toDouble();
    if (!value.isFinite) {
      nonFinite++;
      continue;
    }
    if (value < min) min = value;
    if (value > max) max = value;
    sum += value;
    sumSquares += value * value;
  }
  final norm = sumSquares <= 0 ? 0.0 : math.sqrt(sumSquares);
  return '<vector len=${vector.length} '
      'min=${_fmt(min)} max=${_fmt(max)} '
      'mean=${_fmt(sum / vector.length)} l2=${_fmt(norm)}'
      '${nonFinite > 0 ? ' nonFinite=$nonFinite' : ''}>';
}

String _fmt(double value) =>
    value.isFinite ? value.toStringAsFixed(4) : value.toString();

/// Batasi nilai non-sensitif agar satu baris log tidak meledak.
Object? _shallow(Object? value) {
  if (value is Map) return '<map len=${value.length}>';
  if (value is Uint8List) return '<bytes len=${value.length}>';
  if (value is List) {
    if (value.isNotEmpty && value.first is num) {
      return describeVector(value.cast<num>());
    }
    return '<list len=${value.length}>';
  }
  if (value is String && value.length > 200) {
    return '${value.substring(0, 200)}…<dipotong ${value.length}>';
  }
  return value;
}

String _encodeData(Map<String, Object?> data) {
  try {
    return jsonEncode(data, toEncodable: (value) => value.toString());
  } catch (_) {
    return data.toString();
  }
}

/// Batasi stack trace agar tetap terbaca di logcat.
String _indentStackTrace(StackTrace stackTrace, {int maxLines = 12}) {
  final lines = stackTrace.toString().trimRight().split('\n');
  final shown = lines.take(maxLines).map((line) => '    $line').join('\n');
  if (lines.length <= maxLines) return shown;
  return '$shown\n    …${lines.length - maxLines} baris lagi';
}
