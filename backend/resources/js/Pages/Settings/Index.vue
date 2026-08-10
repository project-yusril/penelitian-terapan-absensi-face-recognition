<script setup>
import { reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import InputError from '@/Components/InputError.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    prodis: { type: Array, default: () => [] },
    selectedProdiId: { type: [Number, null], default: null },
    setting: { type: Object, default: null },
    systemSettings: { type: Object, default: null },
    canManageSystem: { type: Boolean, default: false },
});

const activeTab = ref('prodi'); // 'prodi' | 'system'

const selectedProdi = ref(props.selectedProdiId);

watch(selectedProdi, (id) => {
    router.get(route('settings.index'), { prodi_id: id }, { preserveState: false, preserveScroll: true });
});

const blank = {
    toleransi_masuk_menit: 15, batas_terlambat_persen: 50, toleransi_pulang_menit: 15,
    sp1_jam_mulai: 16, sp1_jam_akhir: 31, sp2_jam_mulai: 32, sp2_jam_akhir: 37,
    sp3_jam_mulai: 38, sp3_jam_akhir: 45, do_jam_mulai: 46,
    face_threshold: 1.0, liveness_challenge_count: 1, liveness_timeout_seconds: 10, max_failed_attempts: 5,
    default_radius_meter: 50, gps_accuracy_minimum: 20, gps_max_age_seconds: 10, allow_offline_attendance: true, offline_sync_timeout_menit: 30,
    sp_warning_percentage: 80,
};

const form = useForm({ ...blank, ...(props.setting ?? {}) });

const save = () => {
    if (!props.selectedProdiId) return;
    form.put(route('settings.update', props.selectedProdiId), { preserveScroll: true });
};

// ---- Konfigurasi global (system_settings) ----
// Bangun map key -> value yang dapat diedit dari struktur grouped.
const flatSystem = {};
const systemGroups = props.systemSettings ?? {};
Object.values(systemGroups).forEach((items) => {
    items.forEach((it) => { flatSystem[it.key] = it.value; });
});
const systemForm = useForm({ values: { ...flatSystem } });

const saveSystem = () => {
    const settings = Object.entries(systemForm.values).map(([key, value]) => ({ key, value }));
    systemForm
        .transform(() => ({ settings }))
        .put(route('settings.system.update'), { preserveScroll: true });
};

const humanize = (s) => (s ?? '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());


const fields = {
    toleransi: [
        { key: 'toleransi_masuk_menit', label: 'Toleransi Masuk (menit)' },
        { key: 'batas_terlambat_persen', label: 'Batas Terlambat (% durasi)' },
        { key: 'toleransi_pulang_menit', label: 'Toleransi Pulang (menit)' },
    ],
    sp: [
        { key: 'sp1_jam_mulai', label: 'SP1 Mulai (jam)' },
        { key: 'sp1_jam_akhir', label: 'SP1 Akhir (jam)' },
        { key: 'sp2_jam_mulai', label: 'SP2 Mulai (jam)' },
        { key: 'sp2_jam_akhir', label: 'SP2 Akhir (jam)' },
        { key: 'sp3_jam_mulai', label: 'SP3 Mulai (jam)' },
        { key: 'sp3_jam_akhir', label: 'SP3 Akhir (jam)' },
        { key: 'do_jam_mulai', label: 'DO Mulai (jam)' },
        { key: 'sp_warning_percentage', label: 'Ambang Peringatan (%)' },
    ],
    face: [
        { key: 'face_threshold', label: 'Threshold Jarak (θ)', step: '0.001' },
        { key: 'liveness_challenge_count', label: 'Jumlah Challenge Liveness' },
        { key: 'liveness_timeout_seconds', label: 'Timeout Liveness (detik)' },
        { key: 'max_failed_attempts', label: 'Maks Gagal Sebelum Flag' },
    ],
    geofence: [
        { key: 'default_radius_meter', label: 'Radius Default (m)' },
        { key: 'gps_accuracy_minimum', label: 'Akurasi GPS Minimum (m)' },
        { key: 'gps_max_age_seconds', label: 'Usia Maksimum Lokasi (detik)' },
        { key: 'offline_sync_timeout_menit', label: 'Timeout Sync Offline (menit)' },
    ],
};
</script>

<template>
    <Head title="Konfigurasi" />
    <PageHeader title="Konfigurasi Sistem" subtitle="Atur toleransi, threshold SP, parameter wajah & geofence per prodi">
        <template #actions>
            <button v-if="activeTab === 'prodi'" class="btn-primary" :disabled="form.processing || !selectedProdiId" @click="save">
                {{ form.processing ? 'Menyimpan...' : 'Simpan Perubahan' }}
            </button>
            <button v-else class="btn-primary" :disabled="systemForm.processing" @click="saveSystem">
                {{ systemForm.processing ? 'Menyimpan...' : 'Simpan Konfigurasi Global' }}
            </button>
        </template>
    </PageHeader>

    <!-- Tab switch (hanya jika user bisa kelola sistem global) -->
    <div v-if="canManageSystem" class="mb-6 flex gap-2 border-b border-slate-200">
        <button
            class="border-b-2 px-4 py-2 text-sm font-medium transition"
            :class="activeTab === 'prodi' ? 'border-brand-600 text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
            @click="activeTab = 'prodi'"
        >
            Per Program Studi
        </button>
        <button
            class="border-b-2 px-4 py-2 text-sm font-medium transition"
            :class="activeTab === 'system' ? 'border-brand-600 text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
            @click="activeTab = 'system'"
        >
            Sistem Global
        </button>
    </div>

    <!-- ===================== TAB: PER PRODI ===================== -->
    <template v-if="activeTab === 'prodi'">
    <div class="mb-6 flex items-center gap-3">
        <label class="text-sm font-medium text-slate-600">Program Studi</label>
        <select v-model.number="selectedProdi" class="input w-auto py-2">
            <option v-for="p in prodis" :key="p.id" :value="p.id">{{ p.nama }}</option>
        </select>
    </div>

    <div v-if="!selectedProdiId" class="card p-10 text-center text-slate-400">
        Belum ada prodi. Tambahkan prodi terlebih dahulu.
    </div>

    <form v-else class="space-y-6" @submit.prevent="save">

        <!-- Toleransi -->
        <section class="card p-5">
            <div class="mb-4 flex items-center gap-2">
                <Icon name="clock" class="h-5 w-5 text-brand-500" />
                <h3 class="text-sm font-semibold text-slate-700">Toleransi Waktu</h3>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div v-for="f in fields.toleransi" :key="f.key">
                    <label class="label">{{ f.label }}</label>
                    <input v-model.number="form[f.key]" type="number" class="input" />
                    <InputError :message="form.errors[f.key]" />
                </div>
            </div>
        </section>

        <!-- SP -->
        <section class="card p-5">
            <div class="mb-4 flex items-center gap-2">
                <Icon name="warning" class="h-5 w-5 text-amber-500" />
                <h3 class="text-sm font-semibold text-slate-700">Threshold Surat Peringatan (akumulasi alpha, jam)</h3>
            </div>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div v-for="f in fields.sp" :key="f.key">
                    <label class="label">{{ f.label }}</label>
                    <input v-model.number="form[f.key]" type="number" class="input" />
                    <InputError :message="form.errors[f.key]" />
                </div>
            </div>
        </section>

        <!-- Face -->
        <section class="card p-5">
            <div class="mb-4 flex items-center gap-2">
                <Icon name="user" class="h-5 w-5 text-violet-500" />
                <h3 class="text-sm font-semibold text-slate-700">Face Recognition (MobileFaceNet)</h3>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                <div v-for="f in fields.face" :key="f.key">
                    <label class="label">{{ f.label }}</label>
                    <input v-model.number="form[f.key]" type="number" :step="f.step ?? '1'" class="input" />
                    <InputError :message="form.errors[f.key]" />
                </div>
            </div>
        </section>

        <!-- Geofence -->
        <section class="card p-5">
            <div class="mb-4 flex items-center gap-2">
                <Icon name="academic" class="h-5 w-5 text-emerald-500" />
                <h3 class="text-sm font-semibold text-slate-700">Geofence & Offline</h3>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                <div v-for="f in fields.geofence" :key="f.key">
                    <label class="label">{{ f.label }}</label>
                    <input v-model.number="form[f.key]" type="number" class="input" />
                    <InputError :message="form.errors[f.key]" />
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input v-model="form.allow_offline_attendance" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-400" />
                        Izinkan absensi offline
                    </label>
                </div>
            </div>
        </section>
    </form>
    </template>

    <!-- ===================== TAB: SISTEM GLOBAL ===================== -->
    <template v-else>
        <div v-if="!systemSettings || Object.keys(systemSettings).length === 0" class="card p-10 text-center text-slate-400">
            Belum ada konfigurasi sistem global.
        </div>
        <form v-else class="space-y-6" @submit.prevent="saveSystem">
            <section v-for="(items, group) in systemSettings" :key="group" class="card p-5">
                <div class="mb-4 flex items-center gap-2">
                    <Icon name="settings" class="h-5 w-5 text-brand-500" />
                    <h3 class="text-sm font-semibold text-slate-700">{{ humanize(group) }}</h3>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div v-for="it in items" :key="it.key">
                        <label class="label">{{ humanize(it.key) }}</label>

                        <!-- boolean -->
                        <label v-if="it.type === 'boolean'" class="mt-1 flex items-center gap-2 text-sm text-slate-600">
                            <input
                                v-model="systemForm.values[it.key]"
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-400"
                            />
                            Aktif
                        </label>

                        <!-- integer -->
                        <input
                            v-else-if="it.type === 'integer'"
                            v-model.number="systemForm.values[it.key]"
                            type="number"
                            class="input"
                        />

                        <!-- string / json / lainnya -->
                        <input
                            v-else
                            v-model="systemForm.values[it.key]"
                            type="text"
                            class="input"
                        />

                        <p v-if="it.description" class="mt-1 text-xs text-slate-400">{{ it.description }}</p>
                    </div>
                </div>
            </section>
        </form>
    </template>
</template>


