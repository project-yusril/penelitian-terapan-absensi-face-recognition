// MS-03 — E2E login + dashboard untuk enam role dashboard.
// Kredensial via env: E2E_<ROLE>_EMAIL / E2E_<ROLE>_PASSWORD.
// Tanpa env, test role tsb di-skip (bukan gagal) agar suite tetap jalan
// sebagian di mesin tanpa seeder lengkap.

// @ts-check
const { test, expect } = require('@playwright/test');

const ROLES = [
  'super_admin',
  'admin_jurusan',
  'admin_prodi',
  'kaprodi',
  'ketua_jurusan',
  'dosen',
];

for (const role of ROLES) {
  const email = process.env[`E2E_${role.toUpperCase()}_EMAIL`];
  const password = process.env[`E2E_${role.toUpperCase()}_PASSWORD`];

  test(`dashboard role ${role}`, async ({ page }) => {
    test.skip(!email || !password, `E2E_${role.toUpperCase()}_EMAIL/PASSWORD tidak diset`);

    await page.goto('/login');
    await page.getByLabel('Email atau NIM').fill(email);
    await page.getByLabel('Kata Sandi').fill(password);
    await page.getByRole('button', { name: /masuk/i }).click();

    // Inertia redirect ke dashboard lalu render halaman Vue.
    await expect(page).toHaveURL(/dashboard/, { timeout: 15_000 });
    await expect(page.locator('header, nav, aside').first()).toBeVisible();

    // Logout memutus sesi (POST /logout lalu redirect ke /login).
    await page.getByRole('button', { name: /keluar/i }).first().click();
    await expect(page).toHaveURL(/login/, { timeout: 15_000 });
  });
}
