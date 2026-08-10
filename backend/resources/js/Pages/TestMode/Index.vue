<script setup>
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    enabled: { type: Boolean, default: false },
    stats: { type: Object, default: () => ({}) },
    unlabeled: { type: Array, default: () => [] },
});

const toggle = () => {
    router.put(route('test-mode.toggle'), { enabled: !props.enabled }, { preserveScroll: true });
};

const label = (logId, value) => {
    router.put(route('test-mode.label', logId), { label: value }, { preserveScroll: true });
};

const fmt = (iso) => {
    if (!iso) return '-';
    const d = new Date(iso);
    return d.toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' });
};
</script>

<template>
    <Head title="Mode Pengujian" />
    <PageHeader title="Mode Pengujian" subtitle="Aktifkan pelabelan data verifikasi wajah untuk evaluasi FAR/FRR penelitian" />

    <div class="card flex items-center justify-between p-6">
        <div class="flex items-start gap-4">
            <div :class="['flex h-12 w-12 items-center justify-center rounded-xl', enabled ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-400']">
                <Icon name="search" class="h-6 w-6" />
            </div>
            <div>
                <p class="font-medium text-slate-700">Status: {{ enabled ? 'Aktif' : 'Nonaktif' }}</p>
                <p class="mt-0.5 max-w-xl text-sm text-slate-500">
                    Saat aktif, sistem akan menandai setiap verifikasi wajah sebagai data uji. Admin
                    melabeli tiap log di tabel bawah sebagai <strong>genuine</strong> (orang yang
                    benar) atau <strong>impostor</strong> (orang lain), lalu lihat FAR/FRR/EER di
                    menu <strong>Analisis</strong>.
                </p>
            </div>
        </div>
        <button
            :class="['relative inline-flex h-7 w-12 items-center rounded-full transition', enabled ? 'bg-emerald-500' : 'bg-slate-300']"
            @click="toggle"
        >
            <span :class="['inline-block h-5 w-5 transform rounded-full bg-white transition', enabled ? 'translate-x-6' : 'translate-x-1']" />
        </button>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="text-sm text-slate-400">Sampel Genuine</p>
            <p class="mt-1 text-2xl font-semibold text-emerald-600">{{ stats.genuine ?? 0 }}</p>
        </div>
        <div class="card p-5">
            <p class="text-sm text-slate-400">Sampel Impostor</p>
            <p class="mt-1 text-2xl font-semibold text-rose-600">{{ stats.impostor ?? 0 }}</p>
        </div>
        <div class="card p-5">
            <p class="text-sm text-slate-400">Total Berlabel</p>
            <p class="mt-1 text-2xl font-semibold text-slate-800">{{ stats.labeled_total ?? 0 }}</p>
        </div>
    </div>

    <div class="card mt-6 p-0">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
                <p class="font-medium text-slate-700">Log Verifikasi Belum Berlabel</p>
                <p class="text-sm text-slate-400">50 entri terbaru saat mode pengujian aktif</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-5 py-3">Waktu</th>
                        <th class="px-5 py-3">User</th>
                        <th class="px-5 py-3">Aksi</th>
                        <th class="px-5 py-3">Distance</th>
                        <th class="px-5 py-3">Threshold</th>
                        <th class="px-5 py-3">Latency (ms)</th>
                        <th class="px-5 py-3 text-right">Label</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="log in unlabeled" :key="log.id" class="text-slate-700">
                        <td class="px-5 py-3 text-slate-500">{{ fmt(log.created_at) }}</td>
                        <td class="px-5 py-3">
                            <p class="font-medium">{{ log.user?.nama ?? '—' }}</p>
                            <p class="text-xs text-slate-400">{{ log.user?.nim ?? '' }}</p>
                        </td>
                        <td class="px-5 py-3 text-xs">
                            <span :class="log.action === 'face_not_match' ? 'text-rose-600' : 'text-emerald-600'">
                                {{ log.action }}
                            </span>
                        </td>
                        <td class="px-5 py-3 font-mono">{{ log.face_distance }}</td>
                        <td class="px-5 py-3 font-mono text-slate-400">{{ log.face_threshold ?? '—' }}</td>
                        <td class="px-5 py-3 font-mono text-slate-400">{{ log.inference_time_ms ?? '—' }}</td>
                        <td class="px-5 py-3">
                            <div class="flex justify-end gap-2">
                                <button
                                    class="rounded-md bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100"
                                    @click="label(log.id, 'genuine')"
                                >Genuine</button>
                                <button
                                    class="rounded-md bg-rose-50 px-3 py-1 text-xs font-medium text-rose-700 hover:bg-rose-100"
                                    @click="label(log.id, 'impostor')"
                                >Impostor</button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!unlabeled.length">
                        <td colspan="7" class="px-5 py-10 text-center text-sm text-slate-400">
                            Belum ada log uji yang perlu dilabeli. Aktifkan mode pengujian lalu
                            lakukan check-in untuk merekam sampel.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <p class="mt-4 text-sm text-slate-400">
        Setelah cukup sampel terkumpul, lihat hasil FAR/FRR/EER + θ optimal di menu <strong>Analisis</strong>.
    </p>
</template>
