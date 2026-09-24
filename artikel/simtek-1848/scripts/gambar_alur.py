"""Diagram alur proses absensi (5 tahap, kolom) — digambar ulang pada ukuran cetak (17 cm)."""
from pathlib import Path

HERE = Path(__file__).resolve().parent
GAMBAR = HERE.parent / 'gambar'
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt
from matplotlib.patches import FancyBboxPatch, Polygon, Ellipse, Circle

plt.rcParams.update({'font.family': 'Arial', 'font.size': 6})
W, H = 17.0, 6.45
fig = plt.figure(figsize=(W / 2.54, H / 2.54), dpi=300)
ax = fig.add_axes([0, 0, 1, 1]); ax.set_xlim(0, W); ax.set_ylim(0, H); ax.axis('off')

COLW = W / 5
NW = 2.45          # lebar node utama
XOFF = 0.28        # jarak node dari tepi kiri kolom
GAP = 0.2
FS = 6.0
STAGES = [
    ('Tahap 1', 'Inisialisasi & Permit', '#2f5d8a', [
        ('start', 'Mulai'),
        ('proc', 'Login dan pilih jadwal\n(check-in/check-out)'),
        ('proc', 'Minta attendance\npermit ke server'),
        ('proc', 'Server memeriksa\npengguna, kelas,\njadwal, periode, waktu'),
        ('dec', 'Permit\nditerbitkan?'),
    ]),
    ('Tahap 2', 'Validasi Lokasi', '#3d7a3a', [
        ('proc', 'Ambil lokasi GPS\n(koordinat, akurasi)'),
        ('dec', 'Akurasi GPS\nmemenuhi?'),
        ('dec', 'Bebas mock\nlocation?'),
        ('proc', 'Hitung jarak d ke\npusat geofence (1)–(2)'),
        ('dec', 'd ≤ radius\ngeofence?'),
    ]),
    ('Tahap 3', 'Validasi Wajah', '#7a3f86', [
        ('proc', 'Deteksi wajah\n(Google ML Kit)'),
        ('dec', 'Tepat satu\nwajah?'),
        ('proc', 'Liveness challenge,\n3 frame konsisten'),
        ('dec', 'Liveness\nlolos?'),
        ('proc', 'Frame netral 112×112\n→ MobileFaceNet\n→ embedding 192-D'),
        ('dec', 'Jarak (3)\nd ≤ θ?'),
    ]),
    ('Tahap 4', 'Validasi Backend', '#9a6b12', [
        ('proc', 'Kirim evidence dan\npermit ke backend'),
        ('dec', 'Permit dan\nbinding valid?'),
        ('proc', 'Konsumsi permit\n(sekali pakai)'),
        ('proc', 'Simpan transaksi\ncheck-in/check-out'),
    ]),
    ('Tahap 5', 'Rekap & Early Warning', '#1f7a7a', [
        ('proc', 'Perbarui rekap\nkehadiran'),
        ('proc', 'Hitung akumulasi alpha\ndan status SP/DO'),
        ('end', 'Selesai'),
    ]),
]
HGT = {'start': 0.4, 'end': 0.4, 'dec': 0.66}


def h_of(kind, text):
    return HGT.get(kind, 0.2 + 0.23 * (text.count('\n') + 1))


def arrow(x1, y1, x2, y2):
    ax.annotate('', xy=(x2, y2), xytext=(x1, y1),
                arrowprops=dict(arrowstyle='-|>', lw=0.5, color='#333333', mutation_scale=5, shrinkA=0, shrinkB=0))


def polyline(pts):
    xs, ys = zip(*pts)
    ax.plot(xs, ys, lw=0.5, color='#333333', solid_capstyle='butt')


TOP = H - 0.08
HEAD_H = 0.6
first_top, last_bottom = [], []
for i, (tag, title, color, nodes) in enumerate(STAGES):
    x0 = i * COLW
    cx = x0 + XOFF + NW / 2
    ax.add_patch(FancyBboxPatch((x0 + 0.1, TOP - HEAD_H), COLW - 0.2, HEAD_H,
                                boxstyle='round,pad=0,rounding_size=0.08', fc=color, ec='none'))
    ax.text(x0 + COLW / 2, TOP - HEAD_H / 2, f'{tag}\n{title}', color='white', ha='center', va='center',
            fontsize=6.4, fontweight='bold', linespacing=1.15)
    y = TOP - HEAD_H - 0.3
    prev_bottom = None
    for k, (kind, text) in enumerate(nodes):
        h = h_of(kind, text)
        top, bottom, cy = y, y - h, y - h / 2
        if prev_bottom is not None:
            arrow(cx, prev_bottom, cx, top)
            if nodes[k - 1][0] == 'dec':
                ax.text(cx + 0.07, (prev_bottom + top) / 2, 'Ya', fontsize=5.3, va='center', color='#333333')
        else:
            first_top.append((cx, top))
        if kind == 'proc':
            ax.add_patch(FancyBboxPatch((cx - NW / 2, bottom), NW, h, boxstyle='round,pad=0,rounding_size=0.06',
                                        fc='white', ec=color, lw=0.6))
        elif kind in ('start', 'end'):
            ax.add_patch(Ellipse((cx, cy), 1.3, h, fc='#e3f1e0', ec='#3d7a3a', lw=0.6))
        else:
            ax.add_patch(Polygon([(cx, top), (cx + NW / 2, cy), (cx, bottom), (cx - NW / 2, cy)], closed=True,
                                 fc='#fff6d6', ec='#9a6b12', lw=0.6))
            xr = cx + NW / 2 + 0.5
            arrow(cx + NW / 2, cy, xr - 0.12, cy)
            ax.text(cx + NW / 2 + 0.03, cy + 0.2, 'Tidak', fontsize=5.3, ha='left', va='center', color='#333333')
            ax.add_patch(Circle((xr, cy), 0.12, fc='#fbe3e3', ec='#b22222', lw=0.6))
            ax.text(xr, cy - 0.005, '×', fontsize=7, ha='center', va='center', color='#b22222', fontweight='bold')
        ax.text(cx, cy, text, ha='center', va='center', fontsize=FS if kind != 'dec' else 5.8, linespacing=1.15)
        prev_bottom = bottom
        y = bottom - GAP
    last_bottom.append((cx, prev_bottom))

# penghubung antartahap: turun, ke celah antarkolom, naik, lalu masuk ke node pertama tahap berikutnya
for i in range(len(STAGES) - 1):
    (bx, by), (tx, ty) = last_bottom[i], first_top[i + 1]
    gx = (i + 1) * COLW + 0.1
    ylow = by - 0.12
    yhigh = ty + 0.15
    polyline([(bx, by), (bx, ylow), (gx, ylow), (gx, yhigh), (tx, yhigh)])
    arrow(tx, yhigh, tx, ty)

fig.savefig(GAMBAR / 'gambar-3-alur.png', dpi=300)
print('lowest connector y (cm): %.2f' % (min(b for _, b in last_bottom) - 0.12))
