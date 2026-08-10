<script setup>
import { computed } from 'vue';

const props = defineProps({
    value: { type: [String, Boolean, null], default: null },
    label: { type: String, default: null },
});

const map = {
    // generic
    aktif: { c: 'bg-emerald-50 text-emerald-700 ring-emerald-200', t: 'Aktif' },
    nonaktif: { c: 'bg-slate-100 text-slate-500 ring-slate-200', t: 'Nonaktif' },
    // attendance
    hadir: { c: 'bg-emerald-50 text-emerald-700 ring-emerald-200', t: 'Hadir' },
    hadir_terlambat: { c: 'bg-amber-50 text-amber-700 ring-amber-200', t: 'Terlambat' },
    alpha: { c: 'bg-rose-50 text-rose-700 ring-rose-200', t: 'Alpha' },
    izin: { c: 'bg-sky-50 text-sky-700 ring-sky-200', t: 'Izin' },
    sakit: { c: 'bg-violet-50 text-violet-700 ring-violet-200', t: 'Sakit' },
    pending: { c: 'bg-slate-100 text-slate-600 ring-slate-200', t: 'Pending' },
    // enrollment
    belum: { c: 'bg-slate-100 text-slate-500 ring-slate-200', t: 'Belum' },
    approved: { c: 'bg-emerald-50 text-emerald-700 ring-emerald-200', t: 'Disetujui' },
    rejected: { c: 'bg-rose-50 text-rose-700 ring-rose-200', t: 'Ditolak' },
};

const resolved = computed(() => {
    const key = String(props.value);
    const item = map[key] ?? { c: 'bg-slate-100 text-slate-600 ring-slate-200', t: props.label ?? key };
    return {
        cls: item.c,
        text: props.label ?? item.t,
    };
});
</script>

<template>
    <span :class="['badge ring-1 ring-inset', resolved.cls]">
        <span class="h-1.5 w-1.5 rounded-full bg-current opacity-70" />
        {{ resolved.text }}
    </span>
</template>
