<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

const page = usePage();
const toasts = ref([]);
let counter = 0;

const push = (type, message) => {
    if (!message) return;
    const id = ++counter;
    toasts.value.push({ id, type, message });
    setTimeout(() => dismiss(id), 4000);
};

const dismiss = (id) => {
    toasts.value = toasts.value.filter((t) => t.id !== id);
};

watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) return;
        push('success', flash.success);
        push('error', flash.error);
        push('info', flash.info);
    },
    { deep: true, immediate: true },
);

const styles = {
    success: { ring: 'border-emerald-200 bg-emerald-50', icon: 'text-emerald-500', text: 'text-emerald-800', name: 'check' },
    error: { ring: 'border-rose-200 bg-rose-50', icon: 'text-rose-500', text: 'text-rose-800', name: 'warning' },
    info: { ring: 'border-brand-200 bg-brand-50', icon: 'text-brand-500', text: 'text-brand-800', name: 'inbox' },
};
</script>

<template>
    <div
        class="pointer-events-none fixed bottom-6 right-6 z-[100] flex w-full max-w-sm flex-col gap-3"
        role="status"
        aria-live="polite"
        aria-atomic="true"
    >
        <transition-group
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="translate-y-2 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                :class="['pointer-events-auto flex items-start gap-3 rounded-xl border p-4 shadow-sm', styles[toast.type].ring]"
            >
                <Icon :name="styles[toast.type].name" :class="['mt-0.5 h-5 w-5 shrink-0', styles[toast.type].icon]" />
                <p :class="['flex-1 text-sm font-medium', styles[toast.type].text]">{{ toast.message }}</p>
                <button type="button" aria-label="Tutup notifikasi" :class="['rounded-md p-0.5 hover:bg-white/60', styles[toast.type].icon]" @click="dismiss(toast.id)">
                    <Icon name="close" class="h-4 w-4" aria-hidden="true" />
                </button>
            </div>
        </transition-group>
    </div>
</template>
