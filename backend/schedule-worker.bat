@echo off
REM ===================================================================
REM  Laravel scheduler worker - dijalankan oleh Windows Task Scheduler.
REM  Menjalankan `php artisan schedule:work` yang akan memicu semua
REM  scheduled command (attendance:mark-absent, auto-close, dll) sesuai
REM  jadwal di routes/console.php. Proses ini berjalan terus-menerus.
REM
REM  Task Scheduler dikonfigurasi untuk:
REM    - start otomatis saat login
REM    - restart otomatis bila proses mati
REM  sehingga scheduler efektif "hidup selamanya" tanpa perintah manual.
REM ===================================================================

if not defined PHP_BIN set "PHP_BIN=php"
set "PROJECT_DIR=%~dp0"

cd /d "%PROJECT_DIR%"
"%PHP_BIN%" artisan schedule:work
