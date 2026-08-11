import 'package:flutter_test/flutter_test.dart';
import 'package:absensi_mahasiswa/core/logging/app_logger.dart';

void main() {
  group('redaksi data sensitif', () {
    test('vektor embedding tidak pernah muncul sebagai angka mentah', () {
      // Nilai penanda yang mustahil muncul kebetulan di ringkasan statistik.
      final embedding = List<double>.filled(192, 0.123456789);

      final hasil = redactMap({'embedding': embedding}).toString();

      expect(hasil, isNot(contains('0.123456789')));
      expect(hasil, contains('len=192'));
    });

    test('token dan password disensor', () {
      final hasil = redactMap({
        'token': 'rahasia-sekali-abcdef',
        'password': 'password123',
        'authorization': 'Bearer xyz',
      }).toString();

      expect(hasil, isNot(contains('rahasia-sekali-abcdef')));
      expect(hasil, isNot(contains('password123')));
      expect(hasil, isNot(contains('Bearer xyz')));
    });

    test('panjang embedding tetap terbaca meski kuncinya sensitif', () {
      // Regresi: sebelumnya nilai ini tersensor jadi "<redacted int>",
      // padahal panjang inilah yang menjelaskan penolakan `size:192` backend.
      final hasil = redactMap({'panjangEmbedding': 192});

      expect(hasil['panjangEmbedding'], 192);
    });

    test('ringkasan yang sudah aman tidak disensor ulang', () {
      // Regresi: describeVector menghasilkan teks aman, tapi karena kuncinya
      // mengandung "embedding" hasilnya sempat jadi "<redacted len=62>".
      final ringkasan = describeVector(List<double>.filled(192, 0.05));

      final hasil = redactMap({'embedding': ringkasan});

      expect(hasil['embedding'], ringkasan);
      expect(hasil['embedding'].toString(), contains('len=192'));
    });
  });

  group('nilai bersarang', () {
    test('map bersarang diringkas jadi <map len=N>', () {
      // Ini alasan LoggingInterceptor meng-encode isi respons error jadi
      // String lebih dulu: kalau dikirim sebagai Map, pesan backend hilang.
      final hasil = redactMap({
        'respons': {'message': 'Anda tidak memiliki akses.', 'code': 'X', 'a': 1},
      });

      expect(hasil['respons'], '<map len=3>');
    });

    test('respons yang sudah di-encode jadi String lolos utuh', () {
      const encoded = '{"message":"Anda tidak memiliki akses.","code":"X"}';

      final hasil = redactMap({'respons': encoded});

      expect(hasil['respons'], encoded);
      expect(hasil['respons'].toString(), contains('tidak memiliki akses'));
    });
  });

  group('describeVector', () {
    test('melaporkan panjang dan norma L2', () {
      // Vektor yang sudah ter-L2-normalisasi: setiap elemen 1/sqrt(4) = 0.5.
      final hasil = describeVector(List<double>.filled(4, 0.5));

      expect(hasil, contains('len=4'));
      expect(hasil, contains('l2=1.0000'));
    });

    test('menandai nilai non-finite yang merusak validasi backend', () {
      // Backend menolak embedding non-finite; ini harus terlihat di log.
      final hasil = describeVector([1.0, double.nan, double.infinity]);

      expect(hasil, contains('nonFinite=2'));
    });

    test('menangani vektor kosong tanpa melempar', () {
      expect(describeVector(const []), contains('len=0'));
    });
  });

  group('buffer log', () {
    test('menyimpan catatan dan bisa diekspor', () {
      AppLogger.clear();
      AppLogger.tag('Uji').info('halo', data: {'token': 'jangan-bocor'});

      final ekspor = AppLogger.export();

      expect(ekspor, contains('[Uji]'));
      expect(ekspor, contains('halo'));
      expect(ekspor, isNot(contains('jangan-bocor')));
    });

    test('buffer dibatasi agar memori tidak tumbuh tanpa batas', () {
      AppLogger.clear();
      for (var i = 0; i < AppLogger.bufferCapacity + 50; i++) {
        AppLogger.tag('Uji').trace('baris $i');
      }

      expect(AppLogger.records.length, AppLogger.bufferCapacity);
      // Baris tertua sudah terdorong keluar.
      expect(AppLogger.export(), isNot(contains('baris 0 ')));
    });
  });

  group('timed', () {
    test('melempar ulang error agar pemanggil tetap menanganinya', () {
      final log = AppLogger.tag('Uji');

      expect(
        () => log.timed<void>('gagal', () async => throw StateError('boom')),
        throwsA(isA<StateError>()),
      );
    });

    test('mengembalikan nilai saat sukses', () async {
      final log = AppLogger.tag('Uji');

      final hasil = await log.timed('sukses', () async => 42);

      expect(hasil, 42);
    });
  });
}
