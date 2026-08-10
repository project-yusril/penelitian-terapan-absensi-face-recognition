import 'package:absensi_mahasiswa/core/utils/formatters.dart';
import 'package:flutter_test/flutter_test.dart';

/// L-06: menguji KODE PRODUCTION `Formatters`, bukan menyalin rumus lokal.
///
/// Perhitungan status kehadiran, alpha, dan ambang SP dilakukan server dan
/// mobile hanya menampilkan hasilnya. Test lama mengulang rumus lokal yang
/// tidak pernah dijalankan aplikasi (false confidence). Test ini mengunci
/// perilaku formatter dan mapper label yang benar-benar dipakai UI.
void main() {
  group('Formatters.formatDuration (produksi)', () {
    test('menit di bawah 60 ditampilkan sebagai menit', () {
      expect(Formatters.formatDuration(5), '5 mnt');
      expect(Formatters.formatDuration(59), '59 mnt');
    });

    test('kelipatan jam tanpa sisa menit', () {
      expect(Formatters.formatDuration(60), '1 jam');
      expect(Formatters.formatDuration(120), '2 jam');
    });

    test('jam dengan sisa menit', () {
      expect(Formatters.formatDuration(150), '2 jam 30 mnt');
      expect(Formatters.formatDuration(90), '1 jam 30 mnt');
    });
  });

  group('Formatters.formatAlphaHours (produksi)', () {
    test('hanya menit saat kurang dari satu jam', () {
      expect(Formatters.formatAlphaHours(45), '45 mnt');
    });

    test('hanya jam saat tanpa sisa menit', () {
      expect(Formatters.formatAlphaHours(120), '2 jam');
    });

    test('jam dan menit', () {
      expect(Formatters.formatAlphaHours(150), '2 jam 30 mnt');
    });
  });

  group('Formatters.formatDistance (produksi)', () {
    test('meter di bawah 1000 tanpa desimal', () {
      expect(Formatters.formatDistance(0), '0m');
      expect(Formatters.formatDistance(999), '999m');
    });

    test('kilometer dengan satu desimal', () {
      expect(Formatters.formatDistance(1000), '1.0km');
      expect(Formatters.formatDistance(2500), '2.5km');
    });
  });

  group('Formatters.formatPercentage (produksi)', () {
    test('satu desimal', () {
      expect(Formatters.formatPercentage(25), '25.0%');
      expect(Formatters.formatPercentage(88.24), '88.2%');
    });
  });

  group('Formatters.getStatusLabel (produksi)', () {
    test('memetakan seluruh status kehadiran', () {
      expect(Formatters.getStatusLabel('hadir'), 'Hadir');
      expect(Formatters.getStatusLabel('hadir_terlambat'), 'Hadir (Terlambat)');
      expect(Formatters.getStatusLabel('pending'), 'Pending');
      expect(Formatters.getStatusLabel('alpha'), 'Alpha');
      expect(Formatters.getStatusLabel('izin'), 'Izin');
      expect(Formatters.getStatusLabel('sakit'), 'Sakit');
    });

    test('case-insensitive dan fallback nilai asli', () {
      expect(Formatters.getStatusLabel('HADIR'), 'Hadir');
      expect(Formatters.getStatusLabel('unknown'), 'unknown');
    });
  });

  group('Formatters.getSpLabel (produksi)', () {
    test('memetakan seluruh status SP', () {
      expect(Formatters.getSpLabel('aman'), 'AMAN');
      expect(Formatters.getSpLabel('sp1'), 'SP 1');
      expect(Formatters.getSpLabel('sp2'), 'SP 2');
      expect(Formatters.getSpLabel('sp3'), 'SP 3');
      expect(Formatters.getSpLabel('do'), 'DO');
    });

    test('case-insensitive dan fallback nilai asli', () {
      expect(Formatters.getSpLabel('SP1'), 'SP 1');
      expect(Formatters.getSpLabel('weird'), 'weird');
    });
  });
}
