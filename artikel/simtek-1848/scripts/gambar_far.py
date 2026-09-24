"""Gambar kurva FAR: histogram jarak impostor (1.485 pasangan) + FAR(θ) + garis θ aktif."""
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt
from matplotlib.ticker import FuncFormatter

from pathlib import Path

HERE = Path(__file__).resolve().parent
GAMBAR = HERE.parent / 'gambar'
SRC = HERE.parents[2] / 'eksperimen' / 'impostor_distances_20260921.txt'  # data FAR asli (repo)
d = [float(x) for x in open(SRC).read().split()]
assert len(d) == 1485, len(d)
N = len(d)

plt.rcParams.update({'font.family': 'Times New Roman', 'font.size': 7, 'axes.linewidth': 0.6})
comma = FuncFormatter(lambda v, _: ('%.1f' % v).replace('.', ','))
comma0 = FuncFormatter(lambda v, _: ('%g' % v).replace('.', ','))

fig, ax = plt.subplots(figsize=(10 / 2.54, 4.6 / 2.54), dpi=300)
bins = [i * 0.05 for i in range(0, 34)]
ax.hist(d, bins=bins, color='#b9c9dd', edgecolor='#5b7aa0', linewidth=0.4, label='Jarak impostor')
ax.set_xlabel('Jarak Euclidean / threshold θ')
ax.set_ylabel('Jumlah pasangan impostor')
ax.set_xlim(0.1, 1.65)
ax.xaxis.set_major_formatter(comma)

ax2 = ax.twinx()
ts = [0.10 + 0.01 * i for i in range(0, 156)]
far = [100.0 * sum(1 for x in d if x <= t) / N for t in ts]
ax2.plot(ts, far, color='#b22222', linewidth=1.1, label='FAR (%)')
ax2.set_ylabel('FAR (%)')
ax2.set_ylim(0, 105)
ax2.yaxis.set_major_formatter(comma0)

theta = 0.60
ax.axvline(theta, color='black', linestyle='--', linewidth=0.8)
ax2.annotate('threshold aktif θ = 0,60\nFAR = 0% (0/1.485)', xy=(theta, 62), xytext=(0.66, 72), fontsize=6.3,
             arrowprops=dict(arrowstyle='->', linewidth=0.6))
mn = min(d)
ax2.annotate('jarak impostor\nminimum = %s' % ('%.4f' % mn).replace('.', ','), xy=(mn, 0.5), xytext=(0.66, 38),
             fontsize=6.3, arrowprops=dict(arrowstyle='->', linewidth=0.6))
h1, l1 = ax.get_legend_handles_labels()
h2, l2 = ax2.get_legend_handles_labels()
ax.legend(h1 + h2, l1 + l2, loc='upper left', bbox_to_anchor=(0.0, 1.0), fontsize=6.3, frameon=False, handlelength=1.2)
for a in (ax, ax2):
    a.tick_params(width=0.6, length=2.5)
fig.tight_layout(pad=0.3)
fig.savefig(GAMBAR / 'gambar-5-kurva-far.png', dpi=300)
# ringkasan untuk teks
def far_at(t):
    return 100.0 * sum(1 for x in d if x <= t + 1e-9) / N
print('min %.4f max %.4f mean %.4f' % (mn, max(d), sum(d) / N))
for t in (0.60, 0.65, 0.70, 0.80, 0.90, 1.00):
    print(t, '%.4f%%' % far_at(t), sum(1 for x in d if x <= t + 1e-9))
