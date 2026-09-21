#!/usr/bin/env bash
set -u
APP="$HOME/domains/absensi.yusrilekamahendra.com/public_html"
ENVF="$APP/.env"
TS=$(date +%Y%m%d_%H%M%S)
BK="$HOME/backups/env_demo_$TS"
WORK="$BK/.env.work"

echo "== 1. Backup .env =="
mkdir -p "$BK" || { echo "MKDIR_FAILED"; exit 1; }
cp "$ENVF" "$BK/.env.restore" || { echo "BACKUP_FAILED"; exit 1; }
chmod 600 "$BK/.env.restore"
PERM=$(stat -c '%a' "$ENVF")
echo "BACKUP_OK $BK/.env.restore (perm asli: $PERM)"

echo "== 2. Flip balik ke production (work file di luar web root) =="
cp "$ENVF" "$WORK"
sed -i 's/^APP_ENV=.*/APP_ENV=production/' "$WORK"
sed -i 's/^BIOMETRIC_ALLOW_CLIENT_CLAIMS=.*/BIOMETRIC_ALLOW_CLIENT_CLAIMS=false/' "$WORK"
if grep -q '^APP_ENV=production$' "$WORK" && grep -q '^BIOMETRIC_ALLOW_CLIENT_CLAIMS=false$' "$WORK"; then
  mv "$WORK" "$ENVF"
  chmod "$PERM" "$ENVF"
  echo "FLIP_BACK_OK"
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
PHPEOF
php "$HOME/demo_envcheck.php"
rm -f "$HOME/demo_envcheck.php"

echo "== 5. Health check =="
curl -s -o /dev/null -w 'HEALTH=%{http_code}\n' https://absensi.yusrilekamahendra.com/api/health

echo "DEMO_OFF_DONE"
