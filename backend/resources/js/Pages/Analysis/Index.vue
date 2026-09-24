<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    prodis: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    verification: { type: Object, required: true },
    geofence: { type: Object, default: () => ({}) },
    latency: { type: Object, default: () => ({}) },
    attendanceSp: { type: Object, default: () => ({}) },
    simultaneous: { type: Object, default: () => ({}) },
});

const prodiId = ref(props.filters.prodi_id ?? '');
const threshold = ref(props.filters.threshold ?? 1.0);
const reload = () => {
    router.get(route('analysis.index'), {
        prodi_id: prodiId.value || undefined,
        threshold: threshold.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const v = computed(() => props.verification);
const maxDist = computed(() => Math.max(1, ...Object.values(v.value.distribution ?? {})));

// Segmen donut distribusi jarak verifikasi (palet gradien biru-ungu).
const DIST_PALETTE = ['#6366f1', '#8b5cf6', '#a855f7', '#d946ef', '#ec4899'];
const distSegments = computed(() => {
    const entries = Object.entries(v.value.distribution ?? {}).filter(([, c]) => Number(c) > 0);
    const total = entries.reduce((s, [, c]) => s + Number(c), 0);
    if (total === 0) return [{ label: 'Belum ada data', raw: 'empty', value: 0, color: '#e2e8f0', offset: 0, pct: 0 }];
    let acc = 0;
    return entries.map(([label, count], i) => {
        const value = Number(count);
        const pct = (value / total) * 100;
        const seg = { label, raw: label, value, color: DIST_PALETTE[i % DIST_PALETTE.length], offset: acc, pct: Math.round(pct * 10) / 10 };
        acc += pct;
        return seg;
    });
});

const geo = computed(() => props.geofence ?? {});
const maxGeo = computed(() => Math.max(1, ...Object.values(geo.value.distribution ?? {})));

const lat = computed(() => props.latency ?? {});
const asp = computed(() => props.attendanceSp ?? {});
const maxTrend = computed(() => Math.max(1, ...(asp.value.weekly_trend ?? []).map((w) => w.total)));
const sim = computed(() => props.simultaneous ?? {});

/* ===== Donut helpers ===== */
// Palet status kehadiran (konsisten AppColors mobile).
const STATUS_PALETTE = {
    hadir: '#10b981',
    hadir_terlambat: '#f59e0b',
    alpha: '#f43f5e',
    izin: '#0ea5e9',
    sakit: '#8b5cf6',
    pending: '#64748b',
};

const SP_PALETTE = {
    aman: '#10b981',
    sp1: '#f59e0b',
    sp2: '#f97316',
    sp3: '#f43f5e',
    do: '#7f1d1d',
};

const statusLabels = {
    hadir: 'Hadir',
    hadir_terlambat: 'Terlambat',
    alpha: 'Alpha',
    izin: 'Izin',
    sakit: 'Sakit',
    pending: 'Pending',
};

const spLabels = { aman: 'Aman', sp1: 'SP 1', sp2: 'SP 2', sp3: 'SP 3', do: 'DO' };

function donutSegments(dist, palette, labels, { emptyColor = '#e2e8f0' } = {}) {
    const entries = Object.entries(dist ?? {}).filter(([, c]) => Number(c) > 0);
    const total = entries.reduce((sum, [, c]) => sum + Number(c), 0);
    if (total === 0) {
        return [{ label: 'Belum ada data', value: 0, color: emptyColor, offset: 0, pct: 0 }];
    }
    let acc = 0;
    return entries.map(([key, count]) => {
        const value = Number(count);
        const pct = (value / total) * 100;
        const segment = {
            label: labels[key] ?? key,
            raw: key,
            value,
            color: palette[key] ?? '#94a3b8',
            offset: acc,
            pct: Math.round(pct * 10) / 10,
        };
        acc += pct;
        return segment;
    });
}

function conicGradient(segments) {
    if (segments.length === 0) return 'conic-gradient(#e2e8f0 0 360deg)';
    const stops = segments.map((s) => `${s.color} ${s.offset}% ${s.offset + s.pct}%`);
    return `conic-gradient(${stops.join(', ')})`;
}

/* ===== FAR/FRR sweep ===== */
const sweep = computed(() => v.value.sweep ?? []);
const maxSweepVal = computed(() => Math.max(10, ...sweep.value.map((p) => Math.max(p.far ?? 0, p.frr ?? 0))));
// Indeks θ optimal untuk penanda garis.
const optIndex = computed(() => {
    const target = v.value.optimal_threshold;
    const idx = sweep.value.findIndex((p) => Math.abs(p.threshold - (target ?? -1)) < 0.03);
    return idx;
});

/* ===== Tren ===== */
const trendEntries = computed(() => (asp.value.weekly_trend ?? []).map((w) => ({
    label: w.week,
    total: w.total,
    hadir: w.hadir,
    alpha: w.alpha,
    pct: w.persentase_hadir,
})));
const trendMax = computed(() => Math.max(1, ...trendEntries.value.map((t) => Math.max(t.total, 1))));
const bestWeek = computed(() => {
    if (!trendEntries.value.length) return null;
    return trendEntries.value.reduce((a, b) => (b.pct > a.pct ? b : a));
});

/* ===== Status ringkas ===== */
const statusSegments = computed(() => donutSegments(asp.value.status_distribution, STATUS_PALETTE, statusLabels));
const spSegments = computed(() => donutSegments(asp.value.sp_level_distribution, SP_PALETTE, spLabels));

const simLevels = computed(() => Object.entries(sim.value.per_concurrent_level ?? {}));
const simTotal = computed(() => sim.value.total_tests ?? 0);

/* ===== Penilaian kualitas otomatis (interpretasi angka) ===== */
// FAR: persen orang asing (impostor) yang BERHASIL memalsukan wajah orang lain.
// Semakin kecil semakin bagus. < 1% sangat bagus, 1-5% wajar, > 5% rawan penipuan.
const farVerdict = computed(() => {
    if (v.value.far === null || v.value.far === undefined) return { tone: 'slate', text: 'Belum ada data impostor berlabel. Aktifkan Mode Pengujian untuk mengumpulkannya.' };
    const f = Number(v.value.far);
    if (f <= 1) return { tone: 'emerald', text: `Bagus. Dari ${v.value.impostor_count} percobaan penipuan (orang asing mengaku wajah orang lain), hanya ${f}% yang lolos. Sistem hampir mustahil ditipu wajah serupa.` };
    if (f <= 5) return { tone: 'amber', text: `Cukup. ${f}% dari ${v.value.impostor_count} percobaan penipuan lolos — masih bisa diterima, tapi sebagian kecil orang asing berhasil absen untuk orang lain.` };
    return { tone: 'rose', text: `Kurang bagus. ${f}% dari ${v.value.impostor_count} percobaan penipuan lolos — sistem rawan disalahgunakan (absen titip wajah). Pertimbangkan menurunkan θ.` };
});

// FRR: persen pemilik wajah ASLI yang justru DITOLAK sistem.
// Semakin kecil semakin nyaman. < 2% sangat bagus, 2-10% wajar, > 10% mengganggu.
const frrVerdict = computed(() => {
    if (v.value.frr === null || v.value.frr === undefined) return { tone: 'slate', text: 'Belum ada data wajah asli berlabel. Aktifkan Mode Pengujian untuk mengumpulkannya.' };
    const f = Number(v.value.frr);
    if (f <= 2) return { tone: 'emerald', text: `Bagus. Hanya ${f}% dari ${v.value.genuine_count} absensi wajah asli yang ditolak — mahasiswa hampir tidak pernah gagal absen karena wajahnya tidak dikenali.` };
    if (f <= 10) return { tone: 'amber', text: `Cukup. ${f}% dari ${v.value.genuine_count} absensi wajah asli ditolak — sebagian kecil mahasiswa perlu mengulang absen (pencahayaan/pose memengaruhi).` };
    return { tone: 'rose', text: `Kurang bagus. ${f}% dari ${v.value.genuine_count} absensi wajah asli ditolak — mahasiswa sering gagal absen walau wajahnya sendiri. Pertimbangkan menaikkan θ.` };
});

// EER: titik tunggal yang meringkas akurasi — kondisi ketika FAR ≈ FRR.
// Semakin kecil semakin akurat model. < 5% sangat baik, 5-10% baik, > 10% lemah.
const eerVerdict = computed(() => {
    if (v.value.eer === null || v.value.eer === undefined) return { tone: 'slate', text: 'EER dihitung dari sweep FAR/FRR terhadap threshold; butuh data genuine & impostor berlabel.' };
    const e = Number(v.value.eer);
    if (e <= 5) return { tone: 'emerald', text: `Sangat baik. EER ${e}% berarti pada titik keseimbangan, error menipu DAN error menolak pemilik asli sama-sama hanya ${e}% — akurasi model MobileFaceNet di dataset ini setara ~${(100 - e).toFixed(1)}%.` };
    if (e <= 10) return { tone: 'amber', text: `Baik. EER ${e}% berarti error keseimbangan sekitar ${e}% — layak untuk absensi dengan θ yang tepat, meski belum seteliti sistem komersial.` };
    return { tone: 'rose', text: `Lemah. EER ${e}% berarti pada titik terbaiknya pun error mencapai ${e}% — model sering tertukar antara menerima penipu dan menolak pemilik asli.` };
});

// θ Optimal: threshold tempat FAR dan FRR paling seimbang (|FAR-FRR| minimum).
// Nilainya bukan "bagus/buruk", tapi panduan setting. Bandingkan dengan θ aktif.
const thresholdVerdict = computed(() => {
    if (v.value.optimal_threshold === null || v.value.optimal_threshold === undefined) return { tone: 'slate', text: 'Diambil dari sweep θ = 0.30–1.40 (step 0.05): titik dengan selisih FAR dan FRR terkecil.' };
    const active = Number(v.value.threshold ?? 0);
    const opt = Number(v.value.optimal_threshold);
    const eerPoint = sweep.value.find((p) => Math.abs(p.threshold - opt) < 0.03);
    const basis = eerPoint ? `Di titik ini FAR ${eerPoint.far ?? '—'}% dan FRR ${eerPoint.frr ?? '—'}% paling seimbang.` : '';
    if (Math.abs(active - opt) < 0.03) {
        return { tone: 'emerald', text: `Setting θ = ${active} sudah tepat — sama dengan titik optimal. ${basis}` };
    }
    const dir = active > opt ? 'lebih ketat' : 'lebih longgar';
    const eff = active > opt
        ? 'menekan penipuan tapi menolak lebih banyak wajah asli'
        : 'mempermudah absensi tapi membuka peluang penipuan lebih besar';
    return { tone: 'amber', text: `Setting θ = ${active} ${dir} dari titik optimal ${opt} — artinya ${eff}. Set θ = ${opt} bila ingin keseimbangan terbaik. ${basis}` };
});

const toneClass = {
    emerald: 'bg-emerald-50 text-emerald-700 border-emerald-100',
    amber: 'bg-amber-50 text-amber-700 border-amber-100',
    rose: 'bg-rose-50 text-rose-700 border-rose-100',
    slate: 'bg-slate-50 text-slate-600 border-slate-100',
};

/* ===== Tabel pendamping (sr-only & print-friendly) ===== */
const distRows = computed(() => Object.entries(v.value.distribution ?? {}));
const geoRows = computed(() => Object.entries(geo.value.distribution ?? {}));
</script>

<template>
    <Head title="Analisis FAR/FRR" />
    <PageHeader title="Analisis & Evaluasi" subtitle="Distribusi jarak wajah, kurva FAR/FRR, EER & threshold optimal (MobileFaceNet)" />

    <!-- Filter -->
    <div class="card mb-5 p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="label" for="filter-prodi">Program Studi</label>
                <select id="filter-prodi" v-model="prodiId" class="input w-auto py-2" aria-describedby="filter-prodi-help" @change="reload">
                    <option value="">Semua prodi (gabungan)</option>
                    <option v-for="p in prodis" :key="p.id" :value="p.id">{{ p.nama }}</option>
                </select>
            </div>
            <div>
                <label class="label" for="filter-threshold">Threshold (θ)</label>
                <input id="filter-threshold" v-model.number="threshold" type="number" step="0.01" class="input w-32" @change="reload" />
            </div>
        </div>
        <p id="filter-prodi-help" class="mt-3 text-sm text-slate-400">
            Filter prodi mempersempit seluruh dataset (genuine/impostor, geofence, latensi, kehadiran), bukan hanya ambang θ.
            Atribusi memakai prodi mahasiswa. Pilihan <strong>gabungan</strong> menggabungkan semua prodi &mdash; jangan laporkan angkanya sebagai hasil satu prodi.
        </p>
    </div>

    <!-- KPI -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="card p-5 transition-shadow hover:shadow-md">
            <div class="flex items-center justify-between">
                <p class="text-sm text-slate-400">FAR @ θ={{ v.threshold }}</p>
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-rose-50 text-rose-600"><Icon name="warning" class="h-4 w-4" /></span>
            </div>
            <p class="mt-2 text-3xl font-semibold text-rose-600">{{ v.far ?? '—' }}<span v-if="v.far !== null" class="text-base">%</span></p>
            <p class="mt-1 text-xs text-slate-400">{{ v.impostor_count }} impostor diuji</p>
            <p class="mt-2 rounded-lg border p-2 text-xs leading-relaxed" :class="toneClass[farVerdict.tone]">{{ farVerdict.text }}</p>
        </div>
        <div class="card p-5 transition-shadow hover:shadow-md">
            <div class="flex items-center justify-between">
                <p class="text-sm text-slate-400">FRR @ θ={{ v.threshold }}</p>
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-50 text-amber-600"><Icon name="clock" class="h-4 w-4" /></span>
            </div>
            <p class="mt-2 text-3xl font-semibold text-amber-600">{{ v.frr ?? '—' }}<span v-if="v.frr !== null" class="text-base">%</span></p>
            <p class="mt-1 text-xs text-slate-400">{{ v.genuine_count }} genuine diuji</p>
            <p class="mt-2 rounded-lg border p-2 text-xs leading-relaxed" :class="toneClass[frrVerdict.tone]">{{ frrVerdict.text }}</p>
        </div>
        <div class="card p-5 transition-shadow hover:shadow-md">
            <div class="flex items-center justify-between">
                <p class="text-sm text-slate-400">EER</p>
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><Icon name="chart" class="h-4 w-4" /></span>
            </div>
            <p class="mt-2 text-3xl font-semibold text-brand-600">{{ v.eer ?? '—' }}<span v-if="v.eer !== null" class="text-base">%</span></p>
            <p class="mt-1 text-xs text-slate-400">Keseimbangan FAR ≈ FRR</p>
            <p class="mt-2 rounded-lg border p-2 text-xs leading-relaxed" :class="toneClass[eerVerdict.tone]">{{ eerVerdict.text }}</p>
        </div>
        <div class="card p-5 transition-shadow hover:shadow-md">
            <div class="flex items-center justify-between">
                <p class="text-sm text-slate-400">θ Optimal</p>
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600"><Icon name="check" class="h-4 w-4" /></span>
            </div>
            <p class="mt-2 text-3xl font-semibold text-emerald-600">{{ v.optimal_threshold ?? '—' }}</p>
            <p class="mt-1 text-xs text-slate-400">Titik EER dari sweep</p>
            <p class="mt-2 rounded-lg border p-2 text-xs leading-relaxed" :class="toneClass[thresholdVerdict.tone]">{{ thresholdVerdict.text }}</p>
        </div>
    </div>

    <!-- ===== Verifikasi: Distribusi & Kurva FAR/FRR ===== -->
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Distribusi distance (donut + bar) -->
        <div class="card p-5">
            <div class="mb-4 flex items-start justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-slate-700">Distribusi Jarak Verifikasi</h3>
                    <p class="text-xs text-slate-400">{{ v.total }} verifikasi · rata-rata {{ v.distance_stats.avg ?? '—' }} · min {{ v.distance_stats.min ?? '—' }} · max {{ v.distance_stats.max ?? '—' }}</p>
                </div>
                <span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700">{{ v.total }} total</span>
            </div>

            <p class="mb-4 rounded-lg bg-slate-50 p-3 text-xs leading-relaxed text-slate-500">
                <strong class="text-slate-600">Artinya:</strong> seberapa mirip wajah yang di-scan dengan wajah terdaftar. Skala 0 = wajah identik, semakin besar = semakin beda.
                Verifikasi diterima bila jarak &le; &theta; ({{ v.threshold }}). <strong class="text-slate-600">Cara membaca:</strong> tumpukan batang yang besar di kiri (rentang 0&ndash;0.4) berarti mayoritas absensi terverifikasi dengan keyakinan tinggi &mdash; bagus.
                Kalau batang menumpuk di kanan (&gt;0.6) berarti verifikasi sering terjadi di ambang batas &mdash; rawan salah tolak/salah terima. Garis &theta; saat ini memisahkan daerah "diterima" (kiri) dan "ditolak" (kanan).
            </p>

            <div class="flex flex-col items-center gap-6 sm:flex-row">
                <!-- Donut -->
                <div class="relative h-36 w-36 shrink-0">
                    <div class="h-full w-full rounded-full transition-transform duration-700 hover:scale-105" :style="{ background: conicGradient(distSegments) }" role="img" aria-label="Diagram lingkaran distribusi jarak verifikasi" />
                    <div class="absolute inset-0 flex flex-col items-center justify-center rounded-full bg-white shadow-inner">
                        <span class="text-2xl font-bold text-slate-800">{{ v.total }}</span>
                        <span class="text-[10px] uppercase tracking-wider text-slate-400">verifikasi</span>
                    </div>
                </div>
                <!-- Legend -->
                <div class="w-full flex-1 space-y-1.5">
                    <div v-for="seg in distSegments" :key="seg.raw" class="flex items-center gap-2 text-sm">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: seg.color }" />
                        <span class="text-slate-500">{{ seg.label }}</span>
                        <span class="ml-auto font-medium text-slate-700">{{ seg.value }}</span>
                        <span class="w-12 text-right text-xs text-slate-400">{{ seg.pct }}%</span>
                    </div>
                </div>
            </div>

            <!-- Bar chart -->
            <div class="mt-6">
                <div class="flex h-40 items-end gap-3" role="img" aria-label="Grafik batang distribusi jarak verifikasi per rentang">
                    <div v-for="(count, label) in v.distribution" :key="label" class="group flex flex-1 flex-col items-center gap-2">
                        <div class="relative flex w-full flex-1 items-end justify-center">
                            <div class="w-full max-w-12 rounded-t-lg bg-gradient-to-t from-brand-600 to-brand-400 transition-all duration-500 group-hover:from-brand-700 group-hover:to-brand-500" :style="{ height: `${(count / maxDist) * 100}%` }" />
                            <!-- Tooltip -->
                            <div class="pointer-events-none absolute -top-7 z-10 hidden whitespace-nowrap rounded-md bg-slate-800 px-2 py-1 text-[11px] font-medium text-white shadow-lg group-hover:block">{{ count }} verifikasi</div>
                        </div>
                        <span class="text-[10px] text-slate-400">{{ label }}</span>
                    </div>
                </div>
            </div>

            <!-- Tabel pendamping -->
            <details class="mt-4 rounded-lg border border-slate-100 bg-slate-50/60">
                <summary class="cursor-pointer px-3 py-2 text-xs font-medium text-slate-500 hover:text-slate-700">Lihat tabel distribusi</summary>
                <table class="w-full text-sm">
                    <caption class="sr-only">Distribusi jarak verifikasi per rentang</caption>
                    <thead><tr class="text-left text-xs uppercase text-slate-400"><th class="px-3 py-2">Rentang</th><th class="px-3 py-2">Jumlah</th><th class="px-3 py-2">Bagian</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="(count, label) in v.distribution" :key="label">
                            <td class="px-3 py-2 text-slate-600">{{ label }}</td>
                            <td class="px-3 py-2 font-medium text-slate-700">{{ count }}</td>
                            <td class="px-3 py-2 text-slate-400">{{ v.total ? ((count / v.total) * 100).toFixed(1) : 0 }}%</td>
                        </tr>
                    </tbody>
                </table>
            </details>
        </div>

        <!-- Kurva FAR/FRR -->
        <div class="card p-5">
            <div class="mb-4 flex items-start justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-slate-700">Kurva FAR vs FRR</h3>
                    <p class="text-xs text-slate-400">{{ v.genuine_count }} genuine · {{ v.impostor_count }} impostor</p>
                </div>
                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">EER {{ v.eer ?? '—' }}%</span>
            </div>

            <p class="mb-4 rounded-lg bg-slate-50 p-3 text-xs leading-relaxed text-slate-500">
                <strong class="text-slate-600">Artinya:</strong> hasil eksperimen menyapu semua kemungkinan &theta; (0.30&ndash;1.40) untuk menunjukkan trade-off dua jenis kesalahan. Batang merah (FAR) = seberapa sering <em>penipu lolos</em>; batang kuning (FRR) = seberapa sering <em>wajah asli ditolak</em>.
                <strong class="text-slate-600">Cara membaca:</strong> FAR turun saat &theta; kecil (sistem ketat), FRR turun saat &theta; besar (sistem longgar) &mdash; keduanya berlawanan arah. Titik potong keduanya adalah EER; garis hijau menandai &theta; optimal di situ.
                <strong class="text-slate-600">Bagus/tidak:</strong> kurva ideal turun tajam di dekat &theta; optimal (transisi tegas antara terima/tolak). Kalau kurva landai, wajah asli dan penipu sulit dipisahkan.
            </p>

            <div v-if="!sweep.length" class="flex h-48 items-center justify-center text-sm text-slate-400">
                <div class="text-center">
                    <Icon name="search" class="mx-auto h-8 w-8" />
                    <p class="mt-2">Belum ada data berlabel. Aktifkan Mode Pengujian untuk mengumpulkan data.</p>
                </div>
            </div>
            <div v-else class="relative">
                <div class="flex h-48 items-end gap-[2px]" role="img" aria-label="Kurva FAR dan FRR terhadap threshold">
                    <!-- FAR -->
                    <div v-for="(pt, i) in sweep" :key="'far-' + pt.threshold" class="group relative flex flex-1 flex-col items-center justify-end">
                        <div class="pointer-events-none absolute -top-8 z-10 hidden whitespace-nowrap rounded-md bg-slate-800 px-2 py-1 text-[11px] font-medium text-white shadow-lg group-hover:block">θ={{ pt.threshold }} · FAR {{ pt.far }}% · FRR {{ pt.frr }}%</div>
                        <div class="w-full rounded-t bg-gradient-to-t from-rose-600 to-rose-400 transition-all duration-300 group-hover:opacity-80" :style="{ height: `${((pt.far ?? 0) / maxSweepVal) * 160}px` }" />
                    </div>
                </div>
                <!-- Penanda θ optimal -->
                <div v-if="optIndex >= 0" class="absolute bottom-0 top-0 w-0.5 bg-emerald-500/70" :style="{ left: `${(optIndex / Math.max(1, sweep.length - 1)) * 100}%` }" :title="`θ optimal ${v.optimal_threshold}`" />
                <!-- Baseline 100% -->
                <div class="pointer-events-none absolute inset-x-0 top-0 text-right text-[10px] text-slate-300">100%</div>
            </div>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded bg-rose-500" /> FAR</span>
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded bg-amber-400" /> FRR</span>
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded bg-emerald-500" /> θ optimal</span>
            </div>
        </div>
    </div>

    <!-- ===== Geofence & Latensi ===== -->
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Evaluasi Geofence -->
        <div class="card p-5">
            <div class="mb-4 flex items-start justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-slate-700">Evaluasi Geofence</h3>
                    <p class="text-xs text-slate-400">{{ geo.total_attempts ?? 0 }} percobaan · jarak rata-rata {{ geo.distance_stats?.avg ?? '—' }} m</p>
                </div>
                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">{{ geo.success_rate ?? 0 }}% sukses</span>
            </div>

            <p class="mb-4 rounded-lg bg-slate-50 p-3 text-xs leading-relaxed text-slate-500">
                <strong class="text-slate-600">Artinya:</strong> penilaian fitur pembatas lokasi &mdash; absensi hanya diterima bila HP berada di dalam radius kampus. Bar hijau/merah = rasio percobaan absen yang berhasil vs gagal (gagal bisa karena di luar radius, atau gagal di tahap wajah/liveness setelahnya).
                Grafik batang menunjukkan seberapa jauh mahasiswa berada dari titik geofence saat absen. <strong class="text-slate-600">Bagus/tidak:</strong> success rate &ge; 90% bagus (absensi lancar tanpa membuka celah absen dari luar kampus); 70&ndash;90% wajar (GPS sering meleset di dalam gedung); &lt; 70% mengganggu &mdash; banyak mahasiswa sah gagal absen karena ketidaktelitian GPS, bukan karena pelanggaran.
                Batang yang menumpuk di 0&ndash;25 m menegaskan absensi memang terjadi di area kampus.
            </p>

            <!-- Ringkasan sukses/gagal -->
            <div class="mb-5 flex h-3 w-full overflow-hidden rounded-full bg-slate-100" role="img" aria-label="Rasio keberhasilan geofence">
                <div class="h-full rounded-l-full bg-emerald-500 transition-all duration-700" :style="{ width: `${geo.success_rate ?? 0}%` }" />
                <div v-if="(geo.failed ?? 0) > 0" class="h-full bg-rose-500" :style="{ width: `${100 - (geo.success_rate ?? 0)}%` }" />
            </div>
            <div class="mb-5 flex flex-wrap gap-3 text-xs text-slate-500">
                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-emerald-500" /> Sukses: <strong class="text-slate-700">{{ geo.success ?? 0 }}</strong></span>
                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-rose-500" /> Gagal: <strong class="text-slate-700">{{ geo.failed ?? 0 }}</strong></span>
            </div>

            <!-- Bar distribusi jarak -->
            <p class="mb-2 text-xs font-medium text-slate-500">Distribusi jarak ke titik geofence</p>
            <div class="flex h-36 items-end gap-3" role="img" aria-label="Grafik batang distribusi jarak ke geofence">
                <div v-for="(count, label) in (geo.distribution ?? {})" :key="label" class="group flex flex-1 flex-col items-center gap-2">
                    <div class="relative flex w-full flex-1 items-end justify-center">
                        <div class="w-full max-w-12 rounded-t-lg bg-gradient-to-t from-emerald-600 to-emerald-400 transition-all duration-500 group-hover:from-emerald-700 group-hover:to-emerald-500" :style="{ height: `${(count / maxGeo) * 100}%` }" />
                        <div class="pointer-events-none absolute -top-7 z-10 hidden whitespace-nowrap rounded-md bg-slate-800 px-2 py-1 text-[11px] font-medium text-white shadow-lg group-hover:block">{{ count }} percobaan</div>
                    </div>
                    <span class="text-[10px] text-slate-400">{{ label }}</span>
                </div>
            </div>

            <!-- Ringkasan statistik jarak -->
            <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                <div class="rounded-lg bg-emerald-50/70 p-2.5">
                    <p class="text-[10px] uppercase tracking-wide text-slate-400">Min</p>
                    <p class="text-sm font-semibold text-slate-700">{{ geo.distance_stats?.min ?? '—' }}<span class="text-[10px] text-slate-400"> m</span></p>
                </div>
                <div class="rounded-lg bg-emerald-50/70 p-2.5">
                    <p class="text-[10px] uppercase tracking-wide text-slate-400">Rata-rata</p>
                    <p class="text-sm font-semibold text-slate-700">{{ geo.distance_stats?.avg ?? '—' }}<span class="text-[10px] text-slate-400"> m</span></p>
                </div>
                <div class="rounded-lg bg-emerald-50/70 p-2.5">
                    <p class="text-[10px] uppercase tracking-wide text-slate-400">Max</p>
                    <p class="text-sm font-semibold text-slate-700">{{ geo.distance_stats?.max ?? '—' }}<span class="text-[10px] text-slate-400"> m</span></p>
                </div>
            </div>
        </div>

        <!-- Latensi Inferensi -->
        <div class="card p-5">
            <div class="mb-4 flex items-start justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-slate-700">Latensi Inferensi (MobileFaceNet)</h3>
                    <p class="text-xs text-slate-400">{{ lat.total_records ?? 0 }} record</p>
                </div>
                <span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700">{{ lat.stats?.avg ?? '—' }} ms avg</span>
            </div>

            <p class="mb-4 rounded-lg bg-slate-50 p-3 text-xs leading-relaxed text-slate-500">
                <strong class="text-slate-600">Artinya:</strong> kecepatan komputasi model pengenalan wajah MobileFaceNet di perangkat mahasiswa &mdash; dari frame kamera sampai keluar hasil pencocokan. Rata-rata, median, P95 (95% pengguna mencapai angka ini atau lebih cepat), dan max diukur per record absensi; daftar <em>Per Perangkat</em> membandingkan HP yang berbeda.
                <strong class="text-slate-600">Bagus/tidak:</strong> untuk absensi mobile, rata-rata &le; 300 ms sangat bagus (terasa instan); 300&ndash;1000 ms masih nyaman; &gt; 1000 ms terasa lambat dan berisiko pengguna menggerakkan HP sebelum proses selesai (gagal absen).
                P95 jauh di atas rata-rata berarti sebagian kecil perangkat lemah tertinggal &mdash; lihat baris perangkatnya. Model ringan seperti MobileFaceNet umumnya menargetkan ratusan milidetik di HP kelas menengah.
            </p>

            <!-- Gauge rata-rata -->
            <div class="mb-5 flex items-center gap-5">
                <div class="relative h-24 w-24 shrink-0">
                    <svg viewBox="0 0 100 100" class="h-full w-full -rotate-90">
                        <circle cx="50" cy="50" r="42" fill="none" stroke="#e2e8f0" stroke-width="10" />
                        <circle
                            cx="50" cy="50" r="42" fill="none"
                            stroke="url(#latencyGradient)" stroke-width="10" stroke-linecap="round"
                            :stroke-dasharray="`${Math.min(100, (lat.stats?.avg ?? 0) / 3)} 100`"
                            class="transition-all duration-1000"
                        />
                        <defs>
                            <linearGradient id="latencyGradient" x1="0" y1="0" x2="1" y2="0">
                                <stop offset="0%" stop-color="#6366f1" />
                                <stop offset="100%" stop-color="#8b5cf6" />
                            </linearGradient>
                        </defs>
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="text-lg font-bold text-slate-800">{{ lat.stats?.avg ?? '—' }}</span>
                        <span class="text-[9px] uppercase tracking-wider text-slate-400">ms</span>
                    </div>
                </div>
                <div class="grid flex-1 grid-cols-2 gap-2 text-center">
                    <div class="rounded-lg bg-slate-50 p-2.5">
                        <p class="text-[10px] uppercase tracking-wide text-slate-400">Min</p>
                        <p class="text-sm font-semibold text-slate-700">{{ lat.stats?.min ?? '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-2.5">
                        <p class="text-[10px] uppercase tracking-wide text-slate-400">Median</p>
                        <p class="text-sm font-semibold text-slate-700">{{ lat.stats?.median ?? '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-2.5">
                        <p class="text-[10px] uppercase tracking-wide text-slate-400">P95</p>
                        <p class="text-sm font-semibold text-slate-700">{{ lat.stats?.p95 ?? '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-2.5">
                        <p class="text-[10px] uppercase tracking-wide text-slate-400">Max</p>
                        <p class="text-sm font-semibold text-slate-700">{{ lat.stats?.max ?? '—' }}</p>
                    </div>
                </div>
            </div>

            <div v-if="lat.per_device && Object.keys(lat.per_device).length" class="mt-4">
                <p class="mb-2 text-xs font-medium text-slate-500">Per Perangkat</p>
                <div class="space-y-2">
                    <div v-for="(d, name) in lat.per_device" :key="name" class="group">
                        <div class="mb-1 flex items-center justify-between text-xs text-slate-500">
                            <span class="truncate font-medium text-slate-600">{{ name }}</span>
                            <span>{{ d.avg ?? '—' }} ms <span class="text-slate-400">({{ d.count }})</span></span>
                        </div>
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500 transition-all duration-700" :style="{ width: `${Math.min(100, ((d.avg ?? 0) / Math.max(1, lat.stats?.max ?? 1)) * 100)}%` }" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Kehadiran & SP + Simultan ===== -->
    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Tren Kehadiran 4 Minggu -->
        <div class="card p-5 lg:col-span-2">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 class="text-sm font-semibold text-slate-700">Tren Kehadiran (4 Minggu)</h3>
                    <p class="text-xs text-slate-400">Total vs hadir vs alpha per minggu</p>
                </div>
                <span v-if="bestWeek" class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                    Terbaik: {{ bestWeek.label }} · {{ bestWeek.pct }}%
                </span>
            </div>

            <p class="mb-4 rounded-lg bg-slate-50 p-3 text-xs leading-relaxed text-slate-500">
                <strong class="text-slate-600">Artinya:</strong> dampak sistem absensi wajah terhadap disiplin mahasiswa dalam 4 minggu terakhir. Batang biru = total jadwal pertemuan, hijau = hadir (termasuk terlambat), merah = alpha (tanpa keterangan); badge kanan-atas menunjukkan minggu terbaik. Donut bawah merinci komposisi status (hadir/terlambat/alpha/izin/sakit) dan level Surat Peringatan (SP 1&ndash;3, DO) yang terakumulasi.
                <strong class="text-slate-600">Bagus/tidak:</strong> persentase hadir &ge; 90% sangat bagus (sistem efektif menekan bolos); 75&ndash;90% baik tapi ada ruang perbaikan; &lt; 75% perlu perhatian. Yang paling penting: tren naik minggu demi minggu menandakan sistem berdampak &mdash; kehadiran membaik karena mahasiswa tidak bisa absen titip. Alpha yang turun dan distribusi SP yang menumpuk di "Aman" adalah indikator keberhasilan penelitian.
            </p>

            <!-- Grouped bar chart -->
            <div class="flex h-52 items-end gap-6" role="img" aria-label="Grafik batang tren kehadiran per minggu">
                <div v-for="w in trendEntries" :key="w.label" class="group flex flex-1 flex-col items-center gap-2">
                    <div class="relative flex h-full w-full items-end justify-center gap-1.5">
                        <!-- Tooltip -->
                        <div class="pointer-events-none absolute -top-8 z-10 hidden whitespace-nowrap rounded-md bg-slate-800 px-2 py-1 text-[11px] font-medium text-white shadow-lg group-hover:block">
                            {{ w.label }} · Total {{ w.total }} · Hadir {{ w.hadir }} · Alpha {{ w.alpha }}
                        </div>
                        <div class="flex h-full w-full max-w-16 flex-col items-center justify-end gap-0.5">
                            <span class="text-[9px] font-semibold text-brand-600">{{ w.total }}</span>
                            <div class="w-full rounded-t-md bg-gradient-to-t from-brand-600 to-brand-400 transition-all duration-500 group-hover:from-brand-700 group-hover:to-brand-500" :style="{ height: `${(w.total / trendMax) * 78}%` }" />
                        </div>
                        <div class="flex h-full w-full max-w-16 flex-col items-center justify-end gap-0.5">
                            <span class="text-[9px] font-semibold text-emerald-600">{{ w.hadir }}</span>
                            <div class="w-full rounded-t-md bg-gradient-to-t from-emerald-600 to-emerald-400 transition-all duration-500 group-hover:from-emerald-700 group-hover:to-emerald-500" :style="{ height: `${(w.hadir / trendMax) * 78}%` }" />
                        </div>
                        <div class="flex h-full w-full max-w-16 flex-col items-center justify-end gap-0.5">
                            <span class="text-[9px] font-semibold text-rose-600">{{ w.alpha }}</span>
                            <div class="w-full rounded-t-md bg-gradient-to-t from-rose-600 to-rose-400 transition-all duration-500 group-hover:from-rose-700 group-hover:to-rose-500" :style="{ height: `${(w.alpha / trendMax) * 78}%` }" />
                        </div>
                    </div>
                    <span class="text-[11px] font-medium text-slate-500">{{ w.label }}</span>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap items-center justify-center gap-4 text-xs text-slate-500">
                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded bg-brand-500" /> Total</span>
                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded bg-emerald-500" /> Hadir</span>
                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded bg-rose-500" /> Alpha</span>
            </div>

            <!-- Tabel pendamping -->
            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-3">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Status Kehadiran</p>
                    <div class="flex items-center gap-4">
                        <!-- Donut kecil -->
                        <div class="relative h-20 w-20 shrink-0">
                            <div class="h-full w-full rounded-full" :style="{ background: conicGradient(statusSegments) }" role="img" aria-label="Diagram lingkaran status kehadiran" />
                            <div class="absolute inset-0 flex items-center justify-center rounded-full bg-white">
                                <span class="text-sm font-bold text-slate-800">{{ statusSegments.reduce((s, x) => s + x.value, 0) }}</span>
                            </div>
                        </div>
                        <div class="flex-1 space-y-1">
                            <div v-for="seg in statusSegments" :key="seg.raw" class="flex items-center gap-2 text-xs">
                                <span class="h-2 w-2 shrink-0 rounded-full" :style="{ background: seg.color }" />
                                <span class="text-slate-500">{{ seg.label }}</span>
                                <span class="ml-auto font-medium text-slate-700">{{ seg.value }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-3">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Distribusi SP</p>
                    <div class="flex items-center gap-4">
                        <div class="relative h-20 w-20 shrink-0">
                            <div class="h-full w-full rounded-full" :style="{ background: conicGradient(spSegments) }" role="img" aria-label="Diagram lingkaran distribusi level SP" />
                            <div class="absolute inset-0 flex items-center justify-center rounded-full bg-white">
                                <span class="text-sm font-bold text-slate-800">{{ spSegments.reduce((s, x) => s + x.value, 0) }}</span>
                            </div>
                        </div>
                        <div class="flex-1 space-y-1">
                            <div v-for="seg in spSegments" :key="seg.raw" class="flex items-center gap-2 text-xs">
                                <span class="h-2 w-2 shrink-0 rounded-full" :style="{ background: seg.color }" />
                                <span class="text-slate-500">{{ seg.label }}</span>
                                <span class="ml-auto font-medium text-slate-700">{{ seg.value }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Uji Simultan -->
        <div class="card p-5 lg:col-span-2">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 class="text-sm font-semibold text-slate-700">Uji Simultan (Concurrent)</h3>
                    <p class="text-xs text-slate-400">{{ simTotal }} pengujian · latensi & keberhasilan per tingkat konkurensi</p>
                </div>
                <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">{{ simLevels.length }} level</span>
            </div>

            <p class="mb-4 rounded-lg bg-slate-50 p-3 text-xs leading-relaxed text-slate-500">
                <strong class="text-slate-600">Artinya:</strong> uji ketahanan sistem saat banyak mahasiswa absen di waktu yang sama (mis. jam istirahat atau menjelang kelas dimulai). "Level" = jumlah absensi yang ditekan bersamaan; untuk tiap level dicatat latensi rata-rata/maksimum dan persentase keberhasilan (badge tabel: hijau &ge; 90%, kuning 70&ndash;90%, merah &lt; 70%).
                <strong class="text-slate-600">Bagus/tidak:</strong> kalau latensi dan success rate tetap stabil saat level naik, backend dan database sanggup menangani jam sibuk &mdash; bagus. Latensi membengkak 2&ndash;3&times; atau success rate anjlok di level tinggi menandakan bottleneck (antrian, koneksi DB, atau server) yang perlu dioptimasi. Sistem yang baik mampu mempertahankan success rate &ge; 90% hingga level konkurensi tertinggi yang diuji.
            </p>

            <div v-if="!simLevels.length" class="flex h-40 items-center justify-center text-center text-sm text-slate-400">
                <div>
                    <Icon name="search" class="mx-auto h-8 w-8" />
                    <p class="mt-2">Belum ada data uji simultan.</p>
                </div>
            </div>
            <template v-else>
                <div class="flex items-end gap-4" role="img" aria-label="Grafik batang latensi dan tingkat keberhasilan per level konkurensi">
                    <div v-for="(row, level) in sim.per_concurrent_level" :key="level" class="group flex flex-1 flex-col items-center gap-2">
                        <div class="relative flex w-full flex-col items-center justify-end">
                            <div class="pointer-events-none absolute -top-7 z-10 hidden whitespace-nowrap rounded-md bg-slate-800 px-2 py-1 text-[11px] font-medium text-white shadow-lg group-hover:block">
                                Level {{ level }} · {{ row.count }} uji · {{ row.avg_latency ?? '—' }} ms · sukses {{ row.success_rate }}%
                            </div>
                            <div class="flex h-32 w-full max-w-14 items-end justify-center">
                                <div class="w-full rounded-t-lg bg-gradient-to-t from-indigo-600 to-indigo-400 transition-all duration-500 group-hover:from-indigo-700 group-hover:to-indigo-500" :style="{ height: `${Math.min(100, ((row.avg_latency ?? 0) / Math.max(1, Math.max(...simLevels.map(([, r]) => r.avg_latency ?? 0)))) * 100)}%` }" />
                            </div>
                            <span class="mt-1 text-sm font-semibold text-slate-700">{{ row.avg_latency ?? '—' }}</span>
                            <span class="text-[9px] uppercase tracking-wide text-slate-400">ms avg</span>
                        </div>
                        <div class="w-full text-center">
                            <p class="text-xs font-semibold text-slate-600">Level {{ level }}</p>
                            <p class="text-[10px] text-slate-400">{{ row.count }} uji</p>
                        </div>
                    </div>
                </div>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-left text-xs uppercase text-slate-400">
                                <th class="py-2">Level</th>
                                <th class="py-2 text-center">Jumlah</th>
                                <th class="py-2 text-center">Avg (ms)</th>
                                <th class="py-2 text-center">Max (ms)</th>
                                <th class="py-2 text-center">Sukses</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="(row, level) in sim.per_concurrent_level" :key="level" class="hover:bg-slate-50/70">
                                <td class="py-2.5 font-medium text-slate-700">{{ level }}</td>
                                <td class="py-2.5 text-center text-slate-600">{{ row.count }}</td>
                                <td class="py-2.5 text-center text-slate-600">{{ row.avg_latency ?? '—' }}</td>
                                <td class="py-2.5 text-center text-slate-600">{{ row.max_latency ?? '—' }}</td>
                                <td class="py-2.5 text-center">
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold" :class="row.success_rate >= 90 ? 'bg-emerald-50 text-emerald-700' : row.success_rate >= 70 ? 'bg-amber-50 text-amber-700' : 'bg-rose-50 text-rose-700'">
                                        {{ row.success_rate }}%
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </div>
</template>
