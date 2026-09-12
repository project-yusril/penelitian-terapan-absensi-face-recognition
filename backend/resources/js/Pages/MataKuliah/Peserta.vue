<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    mataKuliah: { type: Object, required: true },
    enrolled: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="`Peserta — ${mataKuliah.nama}`" />

    <PageHeader :title="`Peserta: ${mataKuliah.nama}`" :subtitle="`${mataKuliah.kode_mk} · ${mataKuliah.prodi ?? '—'}`">
        <template #actions>
            <Link :href="route('mata-kuliah.index')" class="btn-secondary">
                <Icon name="chevron-left" class="h-4 w-4" /> Kembali
            </Link>
        </template>
    </PageHeader>

    <div class="card mb-4 flex items-start gap-3 border-emerald-200 bg-emerald-50/60 p-4 text-sm text-emerald-800">
        <Icon name="info" class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
        <p>
            Peserta mata kuliah diambil otomatis dari <strong>kelas yang mengampu MK ini</strong>
            pada halaman Jadwal. Atur kelas di jadwal untuk menambah/mengurangi peserta.
        </p>
    </div>

    <div class="card overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-4">
            <h3 class="text-sm font-semibold text-slate-700">Peserta ({{ enrolled.length }})</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100">
                <thead>
                    <tr class="bg-slate-50/60 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                        <th class="px-5 py-3">Nama</th>
                        <th class="px-5 py-3">NIM</th>
                        <th class="px-5 py-3">Kelas</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-if="enrolled.length === 0">
                        <td colspan="3" class="px-5 py-10 text-center text-sm text-slate-400">
                            Belum ada peserta. Pastikan MK ini punya jadwal dengan kelas terisi.
                        </td>
                    </tr>
                    <tr v-for="m in enrolled" :key="m.id" class="text-sm hover:bg-slate-50/70">
                        <td class="px-5 py-3 font-medium text-slate-700">{{ m.nama }}</td>
                        <td class="px-5 py-3 text-slate-600">{{ m.nim ?? '—' }}</td>
                        <td class="px-5 py-3 text-slate-600">{{ m.kelas ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
