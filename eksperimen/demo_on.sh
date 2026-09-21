#!/usr/bin/env bash
set -u
APP="$HOME/domains/absensi.yusrilekamahendra.com/public_html"
ENVF="$APP/.env"
TS=$(date +%Y%m%d_%H%M%S)
BK="$HOME/backups/env_demo_$TS"

echo "== 1. Backup .env =="
mkdir -p "$BK" || { echo "MKDIR_FAILED"; exit 1; }
cp "$ENVF" "$BK/.env.demo" || { echo "BACKUP_FAILED"; exit 1; }
chmod 600 "$BK/.env.demo"
gzip -t /dev/null 2>/dev/null
PERM=$(stat -c '%a' "$ENVF")
echo "BACKUP_OK $BK/.env.demo (perm asli: $PERM)"

echo "== 2. Flip BIOMETRIC_ALLOW_CLIENT_CLAIMS (APP_ENV tetap production) =="
WORK="$BK/.env.work"
cp "$ENVF" "$WORK"
sed -i 's/^BIOMETRIC_ALLOW_CLIENT_CLAIMS=.*/BIOMETRIC_ALLOW_CLIENT_CLAIMS=true/' "$WORK"
if grep -q '^APP_ENV=production$' "$WORK" && grep -q '^BIOMETRIC_ALLOW_CLIENT_CLAIMS=true$' "$WORK"; then
  mv "$WORK" "$ENVF"
  chmod "$PERM" "$ENVF"
  echo "FLIP_OK"
else
  rm -f "$WORK"
  echo "SED_FAILED_ABORT"
  exit 1
fi

echo "== 3. Clear config/cache =="
cd "$APP" || exit 1
php artisan config:clear >/dev/null 2>&1 && echo "config:clear OK"
php artisan cache:clear >/dev/null 2>&1 && echo "cache:clear OK"

echo "== 4. Verifikasi runtime Laravel =="
cat > "$HOME/demo_envcheck.php" <<'PHPEOF'
<?php
require '/home/u900314258/domains/absensi.yusrilekamahendra.com/public_html/vendor/autoload.php';
$app = require '/home/u900314258/domains/absensi.yusrilekamahendra.com/public_html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'ENV='.app()->environment().PHP_EOL;
echo 'CLAIMS='.var_export(config('biometric.allow_client_claims'), true).PHP_EOL;
echo 'DEBUG='.var_export(config('app.debug'), true).PHP_EOL;
echo 'DB='.config('database.connections.mysql.database').PHP_EOL;
PHPEOF
php "$HOME/demo_envcheck.php"
rm -f "$HOME/demo_envcheck.php"

echo "== 5. Health check =="
curl -s -o /dev/null -w 'HEALTH=%{http_code}\n' https://absensi.yusrilekamahendra.com/api/health

echo "== 6. Cron log check (sambilan) =="
date -u
ls -la "$HOME/cron-schedule.log" 2>&1
tail -n 5 "$HOME/cron-schedule.log" 2>&1

echo "DEMO_ON_DONE"
