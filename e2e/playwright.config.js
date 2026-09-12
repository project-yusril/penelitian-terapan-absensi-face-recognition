// MS-03 — Browser E2E dashboard: 6 role (super_admin, admin_jurusan,
// admin_prodi, kaprodi, ketua_jurusan, dosen).
//
// Setiap role: login form → dashboard termuat (Inertia page render) →
// logout. Memakai kredensial seeder via env E2E_* (jangan commit password).
//
// Prasyarat: backend berjalan (BASE_URL default http://127.0.0.1:8000) dan
// user seeder tiap role tersedia.
//
// Instal: cd e2e && npm init -y && npm i -D @playwright/test && npx playwright install chromium
// Jalankan: npx playwright test

// @ts-check
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: '.',
  timeout: 60_000,
  retries: 0,
  use: {
    baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8000',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  reporter: [['list'], ['html', { open: 'never' }]],
});
