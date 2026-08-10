# TASK FRONTEND

> **ARSIP HISTORIS/SUPERSEDED.** Rencana standalone Vue SPA digantikan oleh
> Laravel Inertia/Vue di `backend/resources/js`. Lihat
> [CURRENT-ARCHITECTURE.md](CURRENT-ARCHITECTURE.md).
# Sistem Absensi Mahasiswa - Vue.js Web Dashboard Tasks (Low-Level Detail)

---

## ATURAN KERJA

> Setiap task dibuat sangat detail (low-level). Kerjakan secara berurutan.
> Setelah 1 task utama selesai → LAPOR → tunggu konfirmasi → lanjut task berikutnya.
> Task selesai ditandai ✅. Task in-progress ditandai 🔄.

---

## PHASE 7: WEB DASHBOARD (Vue 3 + Vite + Tailwind)
**Estimasi: 3 minggu**

### Task 7.1: Inisialisasi Project Vue
- [x] ✅ Create Vue 3 project via Vite (`npm create vite@latest`) (SELESAI - 2026-05-29)
- [x] ✅ Install dependencies:
  - [x] ✅ `vue-router@4` (routing)
  - [x] ✅ `pinia` (state management)
  - [x] ✅ `axios` (HTTP client)
  - [x] ✅ `tailwindcss` + `@tailwindcss/vite` (v4)
  - [x] ✅ `@headlessui/vue` (UI components)
  - [x] ✅ `@heroicons/vue` (icons)
  - [x] ✅ `vue3-apexcharts` + `apexcharts` (charts)
  - [x] ✅ `vee-validate` + `yup` (form validation)
  - [x] ✅ `vue3-toastify` (toast notifications — Vue 3 compatible)
  - [x] ✅ `katex` (render rumus matematika)
  - [x] ✅ `vue-pdf-embed` (PDF viewer)
  - [x] ✅ `@vuepic/vue-datepicker` (date picker)
  - [x] ✅ `file-saver` (download Excel)
- [x] ✅ Configure Tailwind CSS v4 (`@tailwindcss/vite` — custom colors sesuai design system)
- [x] ✅ Setup folder structure (20 directories: components, layouts, pages, router, stores, services, utils)
- [x] ✅ Setup environment variables (`.env` — API base URL)
- [x] ✅ Test: `npm run dev` berjalan tanpa error

### Task 7.2: Setup Routing & Guards
- [x] ✅ Buat `router/index.js` dengan semua routes (SELESAI - 2026-05-29)
- [x] ✅ Route groups (login, forgot-password, dashboard, academic, users, attendance, sp, reports, settings, analysis, notifications)
- [x] ✅ Buat navigation guard: `beforeEach` — cek token, cek role (SELESAI - 2026-05-29)
- [x] ✅ Buat `meta` per route: `{ requiresAuth: true, roles: [...] }` (SELESAI - 2026-05-29)
- [x] ✅ Redirect unauthorized ke `/login` atau `/403` (SELESAI - 2026-05-29)
- [x] ✅ Test: akses route tanpa login → redirect ke login

### Task 7.3: Setup State Management (Pinia)
- [x] ✅ Buat `stores/auth.js` (state, actions, getters, localStorage persistence) (SELESAI - 2026-05-29)
- [x] ✅ Buat `stores/notification.js` (fetch, markRead, polling) (SELESAI - 2026-05-29)
- [x] ✅ Buat `stores/sidebar.js` (state terintegrasi di DashboardLayout)
- [x] ✅ Buat `stores/settings.js` (terintegrasi di ProdiSettingsPage)
- [x] ✅ Setup Pinia persistence (localStorage untuk token) (SELESAI - 2026-05-29)
- [x] ✅ Test: login → state tersimpan → refresh → masih login

### Task 7.4: Setup API Service Layer
- [x] ✅ Buat `services/api.js` (Axios instance + interceptors) (SELESAI - 2026-05-29)
- [x] ✅ Buat `services/authService.js` (login, logout, me, changePassword, forgotPassword, resetPassword) (SELESAI - 2026-05-29)
- [x] ✅ Buat `services/academicService.js` (SELESAI - 2026-05-29)
- [x] ✅ Buat `services/userService.js` (SELESAI - 2026-05-29)
- [x] ✅ Buat `services/attendanceService.js` (SELESAI - 2026-05-29)
- [x] ✅ Buat `services/spService.js` (SELESAI - 2026-05-29)
- [x] ✅ Buat `services/reportService.js` (SELESAI - 2026-05-29)
- [x] ✅ Buat `services/notificationService.js` (SELESAI - 2026-05-29)
- [x] ✅ Buat `services/settingService.js` (SELESAI - 2026-05-29)
- [x] ✅ Buat `services/analysisService.js` (SELESAI - 2026-05-29)
- [x] ✅ Test: panggil API → response benar

### Task 7.5: Common Components
- [x] ✅ Buat `components/common/AppButton.vue` (variants: primary, secondary, danger, ghost)
- [x] ✅ Buat `components/common/AppInput.vue` (text, email, password, number, textarea)
- [x] ✅ Buat `components/common/AppSelect.vue` (dropdown with search)
- [x] ✅ Buat `components/common/AppModal.vue` (dialog with overlay)
- [x] ✅ Buat `components/common/AppTable.vue` (sortable, pagination, search, filter)
- [x] ✅ Buat `components/common/AppCard.vue` (stat card with icon, value, label, trend)
- [x] ✅ Buat `components/common/AppBadge.vue` (status badges with colors)
- [x] ✅ Buat `components/common/AppAlert.vue` (success, warning, error, info)
- [x] ✅ Buat `components/common/AppPagination.vue`
- [x] ✅ Buat `components/common/AppBreadcrumb.vue`
- [x] ✅ Buat `components/common/AppLoading.vue` (spinner, skeleton)
- [x] ✅ Buat `components/common/AppEmptyState.vue`
- [x] ✅ Buat `components/common/AppConfirmDialog.vue`
- [x] ✅ Buat `components/common/AppFileUpload.vue`
- [x] ✅ Buat `components/common/AppDatePicker.vue`
- [x] ✅ Buat `components/common/AppSearchFilter.vue` (search + filter bar)
- [x] ✅ Semua component menggunakan Tailwind + design system colors
- [x] ✅ Test: render semua component di storybook/test page

### Task 7.6: Layout Components
- [x] ✅ Buat `components/layout/AppHeader.vue` (logo, notification bell, user dropdown) (SELESAI - 2026-05-29)
- [x] ✅ Buat `components/layout/AppSidebar.vue` (collapsible, menu per role, active state, sub-menu) (SELESAI - 2026-05-29)
- [x] ✅ Buat `components/layout/AppFooter.vue` (copyright, version) (SELESAI - 2026-05-29)
- [x] ✅ Buat `layouts/DashboardLayout.vue` (header + sidebar + content + footer, responsive) (SELESAI - 2026-05-29)
- [x] ✅ Buat `layouts/AuthLayout.vue` (centered card, gradient background) (SELESAI - 2026-05-29)
- [x] ✅ Buat sidebar menu config per role (7 roles) (SELESAI - 2026-05-29)
- [x] ✅ Test: layout responsive, sidebar collapse/expand

### Task 7.7: Chart Components
- [x] ✅ Buat `components/charts/LineChart.vue` (wrapper ApexCharts)
- [x] ✅ Buat `components/charts/BarChart.vue`
- [x] ✅ Buat `components/charts/PieChart.vue`
- [x] ✅ Buat `components/charts/StackedBarChart.vue`
- [x] ✅ Buat `components/charts/BoxPlotChart.vue`
- [x] ✅ Buat `components/charts/ScatterChart.vue`
- [x] ✅ Buat `components/charts/ProgressBar.vue` (custom, untuk alpha accumulation)
- [x] ✅ Buat `components/charts/HeatmapChart.vue`
- [x] ✅ Semua chart: props-driven, responsive, theme colors sesuai design system
- [x] ✅ Test: render chart dengan dummy data

### Task 7.8: Auth Pages
- [x] ✅ Buat `pages/auth/LoginPage.vue` (email/NIM + password, remember me, error handling, redirect) (SELESAI - 2026-05-29)
- [x] ✅ Buat `pages/auth/ForgotPasswordPage.vue` (email form, success message) (SELESAI - 2026-05-29)
- [x] ✅ Buat `pages/auth/ResetPasswordPage.vue`
- [x] ✅ Buat `pages/auth/ChangePasswordPage.vue`
- [x] ✅ Test: login flow end-to-end

### Task 7.9: Dashboard Pages (per Role)
- [x] ✅ Buat `pages/dashboard/SuperAdminDashboard.vue`:
  - Cards: total user, total prodi, total mahasiswa, system status
  - Chart: kehadiran seluruh jurusan (line)
  - Table: recent activities / audit trail
- [x] ✅ Buat `pages/dashboard/KajurDashboard.vue`:
  - Cards: total mahasiswa per prodi, total SP per prodi
  - Chart: perbandingan kehadiran antar prodi (grouped bar)
  - Chart: trend SP seluruh jurusan (line)
  - Table: pending SP documents (perlu TTD)
- [x] ✅ Buat `pages/dashboard/AdminJurusanDashboard.vue`:
  - Cards: total mahasiswa, kehadiran hari ini
  - Chart: rekap lintas prodi
  - Table: summary per prodi
- [x] ✅ Buat `pages/dashboard/KaprodiDashboard.vue`:
  - Cards: total mahasiswa prodi, SP1/SP2/SP3/DO count
  - Chart: trend SP per bulan (stacked bar)
  - Chart: persentase kehadiran per MK (horizontal bar)
  - Table: mahasiswa yang baru masuk SP (highlight)
  - Alert: pending SP documents
- [x] ✅ Buat `pages/dashboard/AdminProdiDashboard.vue`:
  - Cards: total mahasiswa aktif, hadir hari ini, alpha hari ini, pending
  - Chart: trend kehadiran mingguan (line)
  - Chart: distribusi status (pie)
  - Chart: top 10 alpha terbanyak (bar)
  - Table: mahasiswa mendekati/sudah SP
  - Table: pending enrollment
- [x] ✅ Buat `pages/dashboard/DosenDashboard.vue`:
  - Cards: kelas hari ini, pending approval, kehadiran rata-rata
  - Table: jadwal hari ini + status
  - Table: pending approval terbaru
- [x] ✅ Dynamic dashboard: render berdasarkan role user yang login
- [x] ✅ Test: setiap dashboard menampilkan data dari API

### Task 7.10: Academic CRUD Pages
- [x] ✅ Buat `pages/academic/TahunAjaranPage.vue`:
  - Table: list tahun ajaran (kode, nama, tanggal, status)
  - Actions: create, edit, delete, activate
  - Modal: form create/edit
  - Confirm dialog: delete, activate
- [x] ✅ Buat `pages/academic/SemesterPage.vue`:
  - Table: list semester (filter by tahun ajaran)
  - Actions: create, edit, delete, activate
  - Modal: form create/edit
  - Warning: aktivasi akan reset alpha
- [x] ✅ Buat `pages/academic/MataKuliahPage.vue`:
  - Table: list MK (filter by semester, prodi)
  - Actions: create, edit, delete
  - Modal: form create/edit (pilih dosen, kelas)
  - Sub-page/modal: assign mahasiswa ke MK (multi-select)
- [x] ✅ Buat `pages/academic/JadwalPage.vue`:
  - Table: list jadwal (filter by MK, hari, dosen)
  - Actions: create, edit, delete
  - Modal: form create/edit (pilih MK, geofence, hari, jam)
  - Validasi bentrok (tampilkan warning)
  - Calendar view (optional)
- [x] ✅ Buat `pages/academic/GeofencePage.vue`:
  - Table: list geofence (nama, koordinat, radius, gedung)
  - Actions: create, edit, delete
  - Modal: form create/edit dengan map picker (Leaflet/Google Maps)
  - Preview radius di peta
- [x] ✅ Test: semua CRUD berfungsi (create, read, update, delete)

### Task 7.11: User Management Pages
- [x] ✅ Buat `pages/users/MahasiswaPage.vue`:
  - Table: list mahasiswa (filter prodi, kelas, angkatan, status, enrollment)
  - Actions: create, edit, delete, view detail
  - Modal: form create/edit
  - Button: import Excel (upload file)
  - Button: export Excel
  - Detail view: data + rekap kehadiran + status SP
- [x] ✅ Buat `pages/users/DosenPage.vue`:
  - Table: list dosen (filter prodi)
  - Actions: create, edit, delete
  - Modal: form create/edit
- [x] ✅ Buat `pages/users/AllUsersPage.vue` (Super Admin):
  - Table: semua user (filter role, prodi, status)
  - Actions: create, edit, delete, assign role, toggle status
  - Modal: form create/edit (pilih role)
- [x] ✅ Test: semua CRUD + import/export

### Task 7.12: Attendance Management Pages
- [x] ✅ Buat `pages/attendance/RekapPage.vue`:
  - Filter: semester, prodi, kelas, MK, mahasiswa
  - Table: rekap kehadiran (matrix view atau list view)
  - Detail per mahasiswa (modal/drawer)
  - Export button (Excel/PDF)
- [x] ✅ Buat `pages/attendance/PendingApprovalPage.vue`:
  - Table: list pending (nama, MK, waktu checkin, keterlambatan)
  - Actions: approve, reject (dengan modal konfirmasi)
  - Bulk approve/reject
- [x] ✅ Buat `pages/attendance/EnrollmentPage.vue`:
  - Table: list enrollment pending (nama, NIM, tanggal, status liveness)
  - Actions: approve, reject (dengan alasan)
  - Tab: pending, approved, rejected
- [x] ✅ Buat `pages/attendance/ReEnrollmentPage.vue`:
  - Table: list re-enrollment requests
  - Actions: approve, reject
- [x] ✅ Buat `pages/attendance/OverridePage.vue` (Dosen):
  - Form: pilih mahasiswa, MK, tanggal, status baru, alasan
  - Submit override
  - Riwayat override
- [x] ✅ Buat `pages/attendance/LeaveRequestPage.vue`:
  - Table: list izin/sakit (filter status)
  - Actions: approve, reject
  - View uploaded file (modal/new tab)
- [x] ✅ Test: semua halaman berfungsi

### Task 7.13: SP Management Pages
- [x] ✅ Buat `pages/sp/MonitoringPage.vue`:
  - Cards: count per SP level
  - Table: mahasiswa + status SP (sortable, filterable)
  - Progress bar per mahasiswa (visual alpha vs threshold)
  - Filter: prodi, kelas, status SP
  - Action: generate SP (untuk admin)
- [x] ✅ Buat `pages/sp/GeneratePage.vue`:
  - Pilih mahasiswa yang eligible untuk SP
  - Preview data (nama, NIM, total alpha, rincian per MK)
  - Button: Generate Draft
  - Button: Kirim ke Kaprodi
- [x] ✅ Buat `pages/sp/DocumentsPage.vue`:
  - Table: list dokumen SP (filter: level, status, prodi)
  - Status flow visual (draft → kaprodi → kajur → final)
  - Actions: sign (kaprodi/kajur), download PDF, cancel
  - PDF preview (vue-pdf-embed)
- [x] ✅ Buat `pages/sp/SignPage.vue` (Kaprodi/Kajur):
  - List dokumen yang menunggu tanda tangan
  - Preview PDF
  - Button: Tanda Tangani & Approve
  - Button: Tolak (dengan alasan)
- [x] ✅ Test: full flow generate → sign → final → download

### Task 7.14: Report & Export Pages
- [x] ✅ Buat `pages/reports/ReportPage.vue`:
  - Tab: Per Mahasiswa, Per MK, Per Kelas, Per Prodi
  - Filter per tab
  - Table + chart visualization
  - Export buttons (Excel, PDF)
- [x] ✅ Buat `pages/reports/MahasiswaDetailReport.vue`:
  - Detail 1 mahasiswa: semua MK, semua pertemuan
  - Timeline kehadiran
  - Alpha accumulation progress
- [x] ✅ Test: report data benar + export berfungsi

### Task 7.15: Settings Pages
- [x] ✅ Buat `pages/settings/ProdiSettingPage.vue`:
  - Form: toleransi masuk, batas terlambat, toleransi pulang
  - Form: threshold SP (SP1, SP2, SP3, DO)
  - Form: face threshold, liveness settings
  - Form: geofence default radius, GPS accuracy
  - Form: notification settings
  - Save button per section
- [x] ✅ Buat `pages/settings/SystemSettingPage.vue` (Super Admin):
  - App name, institution name
  - Test mode toggle
  - Global settings
- [x] ✅ Buat `pages/settings/TestModePage.vue` (Super Admin):
  - Toggle test mode ON/OFF
  - Instruksi penggunaan mode pengujian
  - Status: aktif/nonaktif
- [x] ✅ Test: update settings → verify di database

### Task 7.16: Notification UI
- [x] ✅ Buat notification dropdown di Header:
  - Bell icon + unread count badge
  - Dropdown: list 5 notifikasi terbaru
  - Link: "Lihat semua"
- [x] ✅ Buat `pages/notifications/NotificationPage.vue`:
  - List semua notifikasi (pagination)
  - Filter: type, read/unread
  - Mark as read (individual + all)
  - Click → navigate ke related page
- [x] ✅ Real-time update (polling 30s) (polling setiap 30 detik atau WebSocket)
- [x] ✅ Test: notifikasi muncul, mark read berfungsi

---

## PHASE 11: MENU ANALISIS & EVALUASI SISTEM
**Estimasi: 2 minggu**

### Task 11.1: Evaluasi Geofence Page
- [x] ✅ Buat `pages/analysis/GeofenceEvalPage.vue`
- [x] ✅ Section: Penjelasan Rumus (KaTeX render Haversine formula)
  - Render rumus dengan KaTeX
  - Tabel penjelasan variabel (variabel, penjelasan, sumber data)
- [x] ✅ Section: Data Tabel
  - Table: semua record validasi geofence (dari attendance_logs)
  - Kolom: tanggal, mahasiswa, MK, koordinat, jarak, radius, status, mock location
  - Filter: rentang tanggal, prodi, lokasi, status
  - Pagination
- [x] ✅ Section: Chart & Statistik
  - Pie chart: valid vs invalid
  - Histogram: distribusi jarak
  - Line chart: akurasi GPS rata-rata per hari
  - Stat cards: total percobaan, success rate, rata-rata jarak, mock detected
- [x] ✅ Test: data dari API ditampilkan dengan benar

### Task 11.2: Evaluasi Face Verification Page
- [x] ✅ Buat `pages/analysis/FaceVerifyEvalPage.vue`
- [x] ✅ Section: Penjelasan Rumus (KaTeX)
  - Euclidean Distance formula + penjelasan variabel
  - FAR formula + penjelasan variabel + interpretasi
  - FRR formula + penjelasan variabel + interpretasi
- [x] ✅ Section: Data Tabel (Semua Percobaan)
  - Table: semua percobaan face verification
  - Kolom: tanggal, mahasiswa (akun), jenis (genuine/impostor), distance, threshold, hasil, benar/salah
  - Filter: jenis percobaan, rentang tanggal
- [x] ✅ Section: Hasil FAR & FRR
  - Card: threshold yang digunakan
  - Card genuine: N_genuine, True Accept, N_FR, FRR (%)
  - Card impostor: N_impostor, True Reject, N_FA, FAR (%)
  - Card: Overall Accuracy
- [x] ✅ Section: Chart
  - Scatter plot: distribusi distance (genuine vs impostor, warna beda)
  - Histogram overlay: genuine (biru) vs impostor (merah)
  - Line chart: FAR & FRR vs threshold (untuk cari optimal)
- [x] ✅ Test: kalkulasi FAR/FRR benar

### Task 11.3: Evaluasi Latensi Page
- [x] ✅ Buat `pages/analysis/LatencyEvalPage.vue`
- [x] ✅ Section: Penjelasan Rumus (KaTeX)
  - t_infer formula + penjelasan
  - rata-rata_latensi formula + penjelasan
  - Statistik tambahan (min, max, P95, std dev)
- [x] ✅ Section: Data Tabel
  - Table: semua record inference time
  - Kolom: tanggal, mahasiswa, device model, OS, inference time (ms), kategori device
  - Filter: device model, kategori, rentang tanggal
- [x] ✅ Section: Summary per Device
  - Table: device model, kategori, jumlah test, min, max, rata-rata, P95, std dev
- [x] ✅ Section: Chart
  - Box plot: distribusi latensi per device
  - Bar chart: rata-rata latensi per device
  - Line chart: trend latensi over time
  - Histogram: distribusi waktu inferensi keseluruhan
- [x] ✅ Test: statistik dihitung dengan benar

### Task 11.4: Evaluasi Kehadiran & SP Page
- [x] ✅ Buat `pages/analysis/AttendanceSpEvalPage.vue`
- [x] ✅ Section: Penjelasan Rumus (KaTeX)
  - Persentase kehadiran formula + penjelasan
  - Akumulasi alpha formula + penjelasan
  - Tabel threshold SP
- [x] ✅ Section: Chart & Statistik
  - Stacked bar: distribusi status SP per prodi
  - Line chart: trend akumulasi alpha rata-rata per minggu
  - Pie chart: distribusi status (Aman/SP1/SP2/SP3/DO)
  - Heatmap: kehadiran per mahasiswa per minggu
  - Stat cards: count per status SP, rata-rata persentase kehadiran
- [x] ✅ Section: Detail per Mahasiswa
  - Table: mahasiswa + total alpha + status SP + progress bar
  - Filter: prodi, kelas, status
- [x] ✅ Test: data dan chart sesuai

### Task 11.5: Uji Simultan Page
- [x] ✅ Buat `pages/analysis/SimultaneousTestPage.vue`
- [x] ✅ Section: Penjelasan Parameter
  - Skenario pengujian (20, 30, 40 mahasiswa)
  - Parameter yang diukur (response time, success rate, dll)
- [x] ✅ Section: Tabel Hasil
  - Table: skenario, concurrent users, avg response time, max response time, success/failure/timeout rate
- [x] ✅ Section: Chart
  - Line chart: response time vs concurrent users
  - Bar chart: success/failure/timeout rate per skenario
  - Box plot: distribusi response time per skenario
- [x] ✅ Test: data ditampilkan dengan benar

### Task 11.6: Perbandingan Konvensional vs Sistem Page
- [x] ✅ Buat `pages/analysis/ConventionalComparisonPage.vue`
- [x] ✅ Section: Penjelasan Parameter
  - Apa yang dibandingkan (waktu, akurasi, error, real-time)
- [x] ✅ Section: Form Input Data Konvensional
  - Form: tanggal, MK, jumlah mahasiswa, waktu proses, kesalahan, waktu rekap, catatan
  - Submit → simpan ke backend
  - Table: riwayat data konvensional yang sudah diinput
- [x] ✅ Section: Tabel Perbandingan
  - Table: parameter, konvensional (dari input), sistem digital (dari data aktual), peningkatan
  - Auto-calculate peningkatan (%)
- [x] ✅ Test: input data + perbandingan benar

### Task 11.7: Dokumentasi Teknis Page
- [x] ✅ Buat `pages/analysis/TechnicalDocPage.vue`
- [x] ✅ Accordion/Tab layout:
  - Tab 1: Pra-Pemrosesan Citra (YUV→RGB) — rumus + penjelasan
  - Tab 2: Normalisasi Input — rumus + penjelasan
  - Tab 3: MobileFaceNet Architecture — diagram + penjelasan
  - Tab 4: Euclidean Distance & Threshold — rumus + penjelasan
  - Tab 5: Geofencing (Haversine) — rumus + penjelasan
  - Tab 6: Perhitungan SP — aturan + penjelasan
  - Tab 7: Metrik Evaluasi (FAR, FRR, Latensi) — rumus + penjelasan
- [x] ✅ Semua rumus di-render dengan KaTeX
- [x] ✅ Penjelasan variabel dalam tabel yang rapi
- [x] ✅ Test: semua tab render dengan benar, rumus terbaca
