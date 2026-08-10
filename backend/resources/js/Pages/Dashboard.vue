<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Icon from '@/Components/Icon.vue';
import StatusBadge from '@/Components/StatusBadge.vue';

const props = defineProps({
    stats: { type: Object, required: true },
    statsCards: { type: Array, default: () => [] },
    today: { type: Object, required: true },
    trend: { type: Array, default: () => [] },
    recent: { type: Array, default: () => [] },
    semester: { type: Object, default: null },
    roles: { type: Array, default: () => [] },
    actions: { type: Object, default: () => ({}) },
});


const hasRoute = (name) => {
    try { return !!route().has(name); } catch (e) { return false; }
};

// Kartu "perlu tindakan" — hanya tampil jika ada nilainya & route tersedia.
const actionCards = computed(() => {
    const a = props.actions ?? {};
    const defs = [
        { key: 'attendance_pending', label: 'Kehadiran perlu approval', icon: 'check', route: 'dosen.attendance.index', tone: 'bg-amber-50 text-amber-600' },
        { key: 'enrollment_pending', label: 'Enrollment menunggu', icon: 'user', route: 'enrollments.index', tone: 'bg-brand-50 text-brand-600' },
        { key: 're_enrollment_pending', label: 'Re-enrollment menunggu', icon: 'user', route: 're-enrollments.index', tone: 'bg-sky-50 text-sky-600' },
        { key: 'leave_pending', label: 'Izin/Sakit menunggu', icon: 'inbox', route: 'leave-requests.index', tone: 'bg-violet-50 text-violet-600' },
        { key: 'sp_draft', label: 'SP draft', icon: 'warning', route: 'sp.index', tone: 'bg-rose-50 text-rose-600' },
        { key: 'sp_menunggu_kaprodi', label: 'SP tunggu TTD Kaprodi', icon: 'warning', route: 'sp.index', tone: 'bg-rose-50 text-rose-600' },
        { key: 'sp_menunggu_kajur', label: 'SP tunggu TTD Kajur', icon: 'warning', route: 'sp.index', tone: 'bg-rose-50 text-rose-600' },
    ];
    return defs
        .filter((d) => (a[d.key] ?? 0) > 0 && hasRoute(d.route))
        .map((d) => ({ ...d, value: a[d.key] }));
});

// Kartu statistik utama berasal dari backend (sudah disesuaikan per peran).
// Fallback ke stats global lama bila statsCards kosong.
const cards = computed(() => props.statsCards.length ? props.statsCards : [
    { label: 'Mahasiswa Aktif', value: props.stats.mahasiswa, icon: 'users', tone: 'bg-brand-50 text-brand-600' },
    { label: 'Dosen Aktif', value: props.stats.dosen, icon: 'user', tone: 'bg-emerald-50 text-emerald-600' },
    { label: 'Mata Kuliah', value: props.stats.mata_kuliah, icon: 'book', tone: 'bg-amber-50 text-amber-600' },
    { label: 'Enrollment Pending', value: props.stats.enrollment_pending, icon: 'clock', tone: 'bg-rose-50 text-rose-600' },
]);


const maxTrend = computed(() => Math.max(1, ...props.trend.map((d) => d.total)));

const todayBreakdown = computed(() => [
    { label: 'Hadir', value: props.today.hadir, color: 'bg-emerald-400' },
    { label: 'Terlambat', value: props.today.terlambat, color: 'bg-amber-400' },
    { label: 'Alpha', value: props.today.alpha, color: 'bg-rose-400' },
    { label: 'Izin', value: props.today.izin, color: 'bg-sky-400' },
    { label: 'Sakit', value: props.today.sakit, color: 'bg-violet-400' },
]);
</script>

<template>
    <Head title="Dashboard" />

    <PageHeader title="Dashboard" subtitle="Ringkasan aktivitas sistem absensi mahasiswa" />

    <!-- Action cards (perlu tindakan) -->
    <div v-if="actionCards.length" class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Link
            v-for="card in actionCards"
            :key="card.key"
            :href="route(card.route)"
            class="card flex items-center justify-between p-4 transition hover:shadow-md"
        >
            <div>
                <p class="text-xs text-slate-400">{{ card.label }}</p>
                <p class="mt-1 text-xl font-semibold text-slate-800">{{ card.value }}</p>
            </div>
            <div :class="['flex h-10 w-10 items-center justify-center rounded-xl', card.tone]">
                <Icon :name="card.icon" class="h-5 w-5" />
            </div>
        </Link>
    </div>

    <!-- Stat cards -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div v-for="card in cards" :key="card.label" class="card p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-slate-400">{{ card.label }}</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-800">{{ card.value }}</p>
                </div>
                <div :class="['flex h-12 w-12 items-center justify-center rounded-xl', card.tone]">
                    <Icon :name="card.icon" class="h-6 w-6" />
                </div>
            </div>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Trend chart -->
        <div class="card p-5 lg:col-span-2">
            <div class="mb-5 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-slate-700">Tren Kehadiran 7 Hari</h3>
                    <p class="text-xs text-slate-400">Jumlah absensi tercatat per hari</p>
                </div>
            </div>
            <!-- Representasi visual; alternatif data ada pada tabel di bawah (L-07). -->
            <div class="flex h-48 items-end gap-3" role="img" aria-label="Grafik batang tren kehadiran 7 hari. Data lengkap tersedia pada tabel di bawah.">
                <div v-for="d in trend" :key="d.label" class="flex flex-1 flex-col items-center gap-2">
                    <div class="flex w-full flex-1 items-end justify-center">
                        <div
                            class="w-full max-w-10 rounded-t-lg bg-brand-500/80 transition-all hover:bg-brand-600"
                            :style="{ height: `${(d.total / maxTrend) * 100}%` }"
                            :title="`${d.total} absensi`"
                        />
                    </div>
                    <span class="text-[11px] text-slate-400" aria-hidden="true">{{ d.label }}</span>
                </div>
            </div>
            <table class="sr-only">
                <caption>Tren kehadiran 7 hari, jumlah absensi per hari</caption>
                <thead>
                    <tr><th scope="col">Hari</th><th scope="col">Jumlah absensi</th></tr>
                </thead>
                <tbody>
                    <tr v-for="d in trend" :key="`row-${d.label}`">
                        <th scope="row">{{ d.label }}</th>
                        <td>{{ d.total }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Today breakdown -->
        <div class="card p-5">
            <h3 class="text-sm font-semibold text-slate-700">Kehadiran Hari Ini</h3>
            <p class="text-xs text-slate-400">Total {{ today.total }} record</p>

            <div class="mt-5 space-y-4">
                <div v-for="item in todayBreakdown" :key="item.label">
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="text-slate-500">{{ item.label }}</span>
                        <span class="font-medium text-slate-700">{{ item.value }}</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                        <div
                            :class="['h-full rounded-full', item.color]"
                            :style="{ width: `${today.total ? (item.value / today.total) * 100 : 0}%` }"
                        />
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent activity -->
    <div class="card mt-6 overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-4">
            <h3 class="text-sm font-semibold text-slate-700">Aktivitas Terbaru</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100">
                <thead>
                    <tr class="bg-slate-50/60 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                        <th class="px-5 py-3">Mahasiswa</th>
                        <th class="px-5 py-3">Mata Kuliah</th>
                        <th class="px-5 py-3">Tanggal</th>
                        <th class="px-5 py-3">Check-in</th>
                        <th class="px-5 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-if="recent.length === 0">
                        <td colspan="5" class="px-5 py-10 text-center text-sm text-slate-400">Belum ada aktivitas</td>
                    </tr>
                    <tr v-for="r in recent" :key="r.id" class="text-sm hover:bg-slate-50/70">
                        <td class="px-5 py-3">
                            <p class="font-medium text-slate-700">{{ r.nama ?? '—' }}</p>
                            <p class="text-xs text-slate-400">{{ r.nim ?? '' }}</p>
                        </td>
                        <td class="px-5 py-3 text-slate-600">{{ r.mata_kuliah ?? '—' }}</td>
                        <td class="px-5 py-3 text-slate-600">{{ r.tanggal ?? '—' }}</td>
                        <td class="px-5 py-3 text-slate-600">{{ r.checkin_time ?? '—' }}</td>
                        <td class="px-5 py-3"><StatusBadge :value="r.status" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
