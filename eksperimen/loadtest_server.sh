#!/bin/bash
# ============================================================
# R-02 Load test — dijalankan DARI DALAM SERVER (bypass CDN/WAF)
# Level 20/30/40 mahasiswa simultan.
# READ-ONLY: hanya GET dashboard/jadwal/history, satu token per akun.
# Output: p95 per endpoint + failure rate per level (CSV di stdout akhir).
# ============================================================
APP="$HOME/domains/absensi.yusrilekamahendra.com/public_html"
BASE="http://127.0.0.1"          # lokal di server — tidak lewat CDN/WAF
HOSTHDR="absensi.yusrilekamahendra.com"
# Password akun uji via env: TEST_PASSWORD (jangan di-commit)
TEST_PASSWORD="${TEST_PASSWORD:?Set TEST_PASSWORD sebelum menjalankan}"
# HSTS/force-HTTPS di .htaccess → lokal 301 ke https. Kita minta https lokal
# dgn resolve SNI asli namun Host tetap domain; LiteSpeed serve langsung.
CURL_EXTRA="-s -m 15 --resolve $HOSTHDR:443:127.0.0.1 --insecure"
DB_USER=$(grep '^DB_USERNAME=' "$APP/.env" | cut -d= -f2)
DB_PASS=$(grep '^DB_PASSWORD=' "$APP/.env" | cut -d= -f2-)
DB_NAME="u900314258_absensi"

# --- ambil daftar akun approved (mahasiswa asli ber-foto), max 40
mysql -h 127.0.0.1 -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e "
SELECT DISTINCT u.email
FROM face_embeddings fe
JOIN users u ON u.id = fe.user_id
JOIN user_roles ur ON ur.user_id = u.id
JOIN roles r ON r.id = ur.role_id
WHERE fe.status='approved' AND r.name='mahasiswa' AND u.status='aktif'
ORDER BY u.id LIMIT 40;" 2>/dev/null > /tmp/kilo_emails.txt
EMAILS=()
while IFS= read -r line; do EMAILS+=("$line"); done < /tmp/kilo_emails.txt
echo "accounts: ${#EMAILS[@]}"

# --- login batch: 25/menit max (throttle login per IP 30/menit), token per akun
TOKENS=()
for i in "${!EMAILS[@]}"; do
  body="{\"login\":\"${EMAILS[$i]}\",\"password\":\"$TEST_PASSWORD\",\"device_name\":\"k6-r02\"}"
  resp=$(curl $CURL_EXTRA -o /tmp/kilo_login_$i.json -w "%{http_code}" \
    -H "Content-Type: application/json" \
    -d "$body" "https://$HOSTHDR/api/auth/login")
  tok=$(grep -o '"token":"[^"]*"' "/tmp/kilo_login_$i.json" 2>/dev/null | head -1 | cut -d'"' -f4)
  rm -f "/tmp/kilo_login_$i.json"
  if [ -n "$tok" ]; then TOKENS+=("$tok"); fi
  # jeda agar 30 login/menit per IP tidak terlampaui: 2.2s per login
  sleep 2.2
done
echo "tokens ok: ${#TOKENS[@]}"
if [ "${#TOKENS[@]}" -lt 20 ]; then echo "GAGAL: token kurang dari 20"; exit 1; fi

run_level() {
  local level=$1
  local dur=$2
  local tmp="/tmp/kilo_load_$level.csv"
  echo "ts_ms,endpoint,latency_ms,http_code" > "$tmp"

  # latar: pekerja per akun (level akun pertama), loop request selama $dur detik
  local end=$(( $(date +%s%3N) + dur * 1000 ))
  pids=()
  for ((v=0; v<level; v++)); do
  (
    tok="${TOKENS[$((v % ${#TOKENS[@]}))]}"
    while [ "$(date +%s%3N)" -lt "$end" ]; do
      for ep in dashboard jadwal history; do
        case $ep in
          dashboard) url="/api/mahasiswa/dashboard";;
          jadwal)    url="/api/mahasiswa/jadwal/today";;
          history)   url="/api/mahasiswa/attendance/history?per_page=20&page=1";;
        esac
        t0=$(date +%s%3N)
        code=$(curl $CURL_EXTRA -o /dev/null -w "%{http_code}" \
          -H "Authorization: Bearer $tok" "https://$HOSTHDR$url")
        t1=$(date +%s%3N)
        echo "$t0,$ep,$((t1-t0)),$code" >> "$tmp"
        sleep 0.5
      done
      sleep 2
    done
  ) &
    pids+=($!)
  done
  for p in "${pids[@]}"; do wait "$p"; done

  # analisis level ini (awk: p95 + failure)
  awk -F, -v lvl="$level" '
    NR>1 {
      n[$2]++; lat[$2,n[$2]]=$3; if ($4!="200") fail[$2]++;
      if ($3>mx[$2]) mx[$2]=$3; sum[$2]+=$3
    }
    END {
      for (ep in n) {
        # sort latencies via procedure sederhana: collect + sort
        c=0
        for (i=1;i<=n[ep];i++) { arr[c++]=lat[ep,i] }
        asort(arr)
        idx=int(c*0.95); if (idx<1) idx=1; if (idx>c) idx=c
        printf "%s,%s,%d,%.2f%%,%d\n", lvl, ep, arr[idx-1], fail[ep]/n[ep]*100, n[ep]
      }
    }' "$tmp"
}

echo "level,endpoint,p95_ms,failure_rate,requests"
run_level 20 45
run_level 30 45
run_level 40 45
rm -f /tmp/kilo_load_*.csv
echo "SELESAI"
