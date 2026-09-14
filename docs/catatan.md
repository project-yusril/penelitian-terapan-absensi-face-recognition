# Catatan Splash Screen (sementara — dibuat 14 Sep 2026)

## Status saat ini

Splash screen **dihapus dulu** dari aplikasi agar startup terasa cepat.
Rencananya dibuat belakangan setelah performa utama dibereskan.

## Hasil pengukuran durasi (itel A665L, debug build)

Pengukuran dilakukan dengan polling screenshot ~1 detik sekali sejak cold
start, dan log `[Boot]` + `[Bloc]` di logcat.

| Fase | Durasi | Yang terlihat user |
|---|---|---|
| Boot engine Flutter (debug) | ~4,3 detik | *system splash* Android 12+ (logo launcher dalam lingkaran) |
| Splash in-app (durasi 3 detik) | 3 detik | logo POLNEP penuh |
| Verifikasi sesi ke server (`GET /api/user`) | ~2,5 detik | logo + spinner |
| **Total sampai login** | **~8–11 detik** | |

## Kesimpulan pengukuran

Realistanya — splash in-app tepat 3 detik sudah tercapai. Tapi total sampai
login tetap ~8–11 detik di HP ini karena boot engine + verifikasi server —
dua hal di luar kontrol durasi splash. Yang bisa memangkasnya:

1. **Build release** — boot 2–3× lebih cepat dari debug, tapi butuh keystore
   signing yang belum ada di mesin ini.
2. **HTTP/2 atau keep-alive connection ke server** untuk mempercepat
   verifikasi token.

## Rencana implementasi splash belakangan (catatan teknis)

Yang sudah terbukti berfungsi saat percobaan ini dan bisa dipakai ulang:

- Android 12+ (API 31) **mengabaikan** `windowBackground` dari `LaunchTheme`
  saat cold start dan memakai *system splash* bawaan OS. Solusinya override
  `LaunchTheme` di `values-v31/styles.xml` dengan:
  - `android:windowSplashScreenBackground` (warna latar)
  - `android:windowSplashScreenAnimatedIcon` → drawable inset (logo dibungkus
    inset 25% agar tidak terpotong mask lingkaran)
- Splash in-app Flutter: widget logo penuh + `SplashClock` (durasi dihitung
  dari baris pertama `main()`, bukan setelah boot, supaya boot time masuk ke
  dalam durasi dan tidak menambah total).
- Widget splash perlu `Timer` internal untuk menampilkan spinner "memverifikasi
  sesi" setelah durasi habis, karena BlocBuilder tidak rebuild saat waktu
  berjalan.
- Icon launcher (`face-id.png`, 5 density mipmap) **tidak dihapus** — tetap
  dipakai sebagai ikon aplikasi.

## Untuk melanjutkan nanti

1. Buat keystore release + `key.properties` (build.gradle.kts sudah siap
   membacanya; env var `ANDROID_KEYSTORE_*` juga didukung).
2. Implementasikan ulang splash native (v31) + splash in-app sesuai catatan
   di atas dengan durasi 3 detik.
3. Ukur ulang total waktu sampai login di build release.
