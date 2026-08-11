import 'package:flutter_test/flutter_test.dart';
import 'package:intl/intl.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:absensi_mahasiswa/core/utils/location_utils.dart';
import 'package:absensi_mahasiswa/core/utils/validators.dart';
import 'package:absensi_mahasiswa/core/utils/formatters.dart';

void main() {
  // formatDateFromIso memakai locale id_ID; aplikasi memuatnya saat boot,
  // jadi test harus melakukan hal yang sama.
  setUpAll(() => initializeDateFormatting('id_ID'));

  group('LocationUtils', () {
    group('haversineDistance', () {
      test('should return 0 for same point', () {
        final distance = LocationUtils.haversineDistance(
          -0.0234,
          109.3456,
          -0.0234,
          109.3456,
        );
        expect(distance, equals(0.0));
      });

      test('should calculate distance between two points correctly', () {
        // Known distance: ~25m between two close points
        final distance = LocationUtils.haversineDistance(
          -0.0234,
          109.3456,
          -0.0236,
          109.3458,
        );
        expect(distance, greaterThan(0));
        expect(distance, lessThan(100));
      });

      test('should calculate ~111km for 1 degree latitude difference', () {
        final distance = LocationUtils.haversineDistance(0.0, 0.0, 1.0, 0.0);
        // 1 degree latitude ≈ 111.19 km
        expect(distance, closeTo(111195, 1000));
      });
    });

    group('isWithinGeofence', () {
      test('should return true when within radius', () {
        final result = LocationUtils.isWithinGeofence(
          -0.0234,
          109.3456,
          -0.0234,
          109.3456,
          50.0,
        );
        expect(result, isTrue);
      });

      test('should return false when outside radius', () {
        final result = LocationUtils.isWithinGeofence(
          -0.0234,
          109.3456,
          -0.0300,
          109.3500,
          50.0,
        );
        expect(result, isFalse);
      });

      test('should return true when exactly at boundary', () {
        // Point very close to center
        final result = LocationUtils.isWithinGeofence(
          -0.02340,
          109.34560,
          -0.02341,
          109.34561,
          50.0,
        );
        expect(result, isTrue);
      });
    });
  });

  group('Validators', () {
    group('validateEmail', () {
      test('should return null for valid email', () {
        expect(Validators.validateEmail('test@example.com'), isNull);
      });

      test('should return error for empty email', () {
        expect(Validators.validateEmail(''), isNotNull);
      });

      test('should return error for invalid email', () {
        expect(Validators.validateEmail('invalid'), isNotNull);
        expect(Validators.validateEmail('test@'), isNotNull);
        expect(Validators.validateEmail('@domain.com'), isNotNull);
      });

      test('should return null for null input', () {
        expect(Validators.validateEmail(null), isNotNull);
      });
    });

    group('validatePassword', () {
      test('should return null for valid password', () {
        expect(Validators.validatePassword('password123'), isNull);
      });

      test('should return error for empty password', () {
        expect(Validators.validatePassword(''), isNotNull);
      });

      test('should return error for short password', () {
        expect(Validators.validatePassword('abc1'), isNotNull);
      });

      test('should return error for password without numbers', () {
        expect(Validators.validatePassword('abcdefgh'), isNotNull);
      });

      test('should return error for password without letters', () {
        expect(Validators.validatePassword('12345678'), isNotNull);
      });
    });

    group('validateNIM', () {
      test('should return null for valid NIM', () {
        expect(Validators.validateNIM('2024001001'), isNull);
      });

      test('should return error for empty NIM', () {
        expect(Validators.validateNIM(''), isNotNull);
      });

      test('should return error for short NIM', () {
        expect(Validators.validateNIM('123'), isNotNull);
      });
    });

    group('validateRequired', () {
      test('should return null for non-empty value', () {
        expect(Validators.validateRequired('value', 'Field'), isNull);
      });

      test('should return error for empty value', () {
        expect(Validators.validateRequired('', 'Field'), isNotNull);
      });

      test('should return error for null value', () {
        expect(Validators.validateRequired(null, 'Field'), isNotNull);
      });
    });
  });

  group('Formatters waktu tampilan', () {
    test('formatClockFromIso mengubah UTC backend jadi jam lokal', () {
      // Backend mengirim UTC; user harus melihat jam setempat.
      final waktu = DateTime.utc(2026, 8, 11, 6, 58, 7);
      final harapan = DateFormat('HH:mm').format(waktu.toLocal());

      expect(Formatters.formatClockFromIso(waktu.toIso8601String()), harapan);
      // Jam UTC mentah tidak boleh bocor ke tampilan.
      expect(Formatters.formatClockFromIso(waktu.toIso8601String()), isNot(contains('T')));
      expect(Formatters.formatClockFromIso(waktu.toIso8601String()).length, 5);
    });

    test('formatClockFromIso tidak pernah membocorkan string mentah', () {
      // Regresi: timestamp ISO mentah pernah tampil di kartu jadwal dan
      // merusak tata letaknya sampai judul pecah per huruf.
      for (final buruk in [null, '', '   ', 'bukan-tanggal']) {
        expect(Formatters.formatClockFromIso(buruk), '-');
      }
      expect(
        Formatters.formatClockFromIso(null, fallback: '—'),
        '—',
      );
    });

    test('trimSeconds memangkas detik dari jam jadwal', () {
      expect(Formatters.trimSeconds('13:00:00'), '13:00');
      expect(Formatters.trimSeconds('08:30'), '08:30');
      expect(Formatters.trimSeconds('9:5:00'), '09:05');
    });

    test('trimSeconds aman untuk nilai kosong', () {
      expect(Formatters.trimSeconds(null), '-');
      expect(Formatters.trimSeconds(''), '-');
    });

    test('formatDateFromIso memakai nama bulan Indonesia', () {
      // Butuh locale id_ID; test ini sekaligus memastikan data locale
      // tersedia lewat initializeDateFormatting di setUpAll.
      expect(
        Formatters.formatDateFromIso('2026-08-11T00:00:00.000000Z'),
        contains('2026'),
      );
      expect(Formatters.formatDateFromIso(null), '-');
      expect(Formatters.formatDateFromIso('bukan-tanggal'), '-');
    });
  });

  group('Formatters', () {
    test('formatDuration should format minutes correctly', () {
      expect(Formatters.formatDuration(30), equals('30 mnt'));
      expect(Formatters.formatDuration(60), equals('1 jam'));
      expect(Formatters.formatDuration(90), equals('1 jam 30 mnt'));
      expect(Formatters.formatDuration(120), equals('2 jam'));
    });

    test('formatDistance should format meters correctly', () {
      expect(Formatters.formatDistance(50), equals('50m'));
      expect(Formatters.formatDistance(1500), equals('1.5km'));
    });

    test('formatPercentage should format correctly', () {
      expect(Formatters.formatPercentage(85.5), equals('85.5%'));
      expect(Formatters.formatPercentage(100), equals('100.0%'));
    });

    test('formatAlphaHours should format correctly', () {
      expect(Formatters.formatAlphaHours(30), equals('30 mnt'));
      expect(Formatters.formatAlphaHours(90), equals('1 jam 30 mnt'));
      expect(Formatters.formatAlphaHours(120), equals('2 jam'));
    });

    test('getStatusLabel should return correct labels', () {
      expect(Formatters.getStatusLabel('hadir'), equals('Hadir'));
      expect(
        Formatters.getStatusLabel('hadir_terlambat'),
        equals('Hadir (Terlambat)'),
      );
      expect(Formatters.getStatusLabel('pending'), equals('Pending'));
      expect(Formatters.getStatusLabel('alpha'), equals('Alpha'));
      expect(Formatters.getStatusLabel('izin'), equals('Izin'));
      expect(Formatters.getStatusLabel('sakit'), equals('Sakit'));
    });

    test('getSpLabel should return correct labels', () {
      expect(Formatters.getSpLabel('aman'), equals('AMAN'));
      expect(Formatters.getSpLabel('sp1'), equals('SP 1'));
      expect(Formatters.getSpLabel('sp2'), equals('SP 2'));
      expect(Formatters.getSpLabel('sp3'), equals('SP 3'));
      expect(Formatters.getSpLabel('do'), equals('DO'));
    });

    test('getGreeting should return based on time', () {
      final greeting = Formatters.getGreeting();
      expect(
        greeting,
        anyOf(
          equals('Selamat Pagi'),
          equals('Selamat Siang'),
          equals('Selamat Sore'),
          equals('Selamat Malam'),
        ),
      );
    });
  });
}
