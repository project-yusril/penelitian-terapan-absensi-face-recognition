# MS-03 — Browser E2E Dashboard (Playwright)

> Melengkapi milestone MS-03 di `docs/temuan.md`: E2E browser untuk enam role
> dashboard (super_admin, admin_jurusan, admin_prodi, kaprodi, ketua_jurusan,
> dosen). Mobile E2E tetap tercakup `frontend/integration_test/` (H-16).

## Setup (sekali)

```powershell
cd e2e
npm init -y
npm i -D @playwright/test
npx playwright install chromium
```

## Kredensial

Kredensial role diambil dari env (jangan commit password):

```powershell
$env:E2E_SUPER_ADMIN_EMAIL = "..."
$env:E2E_SUPER_ADMIN_PASSWORD = "..."
$env:E2E_ADMIN_JURUSAN_EMAIL = "..."
# dst. untuk ADMIN_PRODI, KAPRODI, KETUA_JURUSAN, DOSEN
```

Tanpa env, test role terkait di-skip (bukan gagal) supaya suite bisa jalan
parsel.

## Menjalankan

```powershell
# Backend harus berjalan + database ter-seed:
php artisan serve   # (atau serve:all)

cd e2e
npx playwright test
npx playwright show-report   # bukti HTML + trace/screenshot gagalan
```

## Yang diuji per role

1. `GET /login` menampilkan form (Inertia).
2. Login sukses → redirect `/dashboard`, layout termuat.
3. Logout → kembali ke `/login`, sesi putus.

Skenario lanjutan (menu spesifik per role: SP, approval, report, dst.) dapat
ditambah sebagai spec terpisah mengikuti pola yang sama.

## Batasan

- Hasil lokal membuktikan flow browser jalan; klaim MS-03 `[X]` tetap
  membutuhkan bukti tersimpan (HTML report + env tercatat) sesuai konvensi
  README dokumentasi.
- Laravel session memakai cookie stateful; Playwright menjalankan browser
  sungguhan sehingga CSRF/cookie otomatis ditangani.
