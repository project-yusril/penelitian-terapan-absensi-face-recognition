<script setup>
import { watch, onUnmounted, ref, nextTick, useId } from 'vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: '' },
    maxWidth: { type: String, default: 'xl' },
});

const emit = defineEmits(['close']);

const maxWidthClass = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-xl',
    '2xl': 'max-w-2xl',
    '3xl': 'max-w-3xl',
};

const close = () => emit('close');

// L-07: dialog semantics, focus trap, dan pengembalian fokus.
const titleId = useId();
const dialogRef = ref(null);
let previouslyFocused = null;

const focusableSelector = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

const focusableElements = () => {
    if (!dialogRef.value) return [];
    return Array.from(dialogRef.value.querySelectorAll(focusableSelector));
};

const trapFocus = (e) => {
    const focusable = focusableElements();
    if (focusable.length === 0) {
        e.preventDefault();
        dialogRef.value?.focus();
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
};

const onKey = (e) => {
    if (!props.show) return;
    if (e.key === 'Escape') {
        close();
        return;
    }
    if (e.key === 'Tab') trapFocus(e);
};

watch(
    () => props.show,
    async (value) => {
        if (typeof document === 'undefined') return;
        document.body.style.overflow = value ? 'hidden' : '';
        if (value) {
            previouslyFocused = document.activeElement;
            document.addEventListener('keydown', onKey);
            await nextTick();
            const focusable = focusableElements();
            (focusable[0] ?? dialogRef.value)?.focus();
        } else {
            document.removeEventListener('keydown', onKey);
            previouslyFocused?.focus?.();
            previouslyFocused = null;
        }
    },
);

onUnmounted(() => {
    if (typeof document !== 'undefined') {
        document.body.style.overflow = '';
        document.removeEventListener('keydown', onKey);
    }
});
</script>

<template>
    <teleport to="body">
        <transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="show" class="fixed inset-0 z-[90] flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" aria-hidden="true" @click="close" />

                <transition
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="translate-y-3 scale-95 opacity-0"
                    enter-to-class="translate-y-0 scale-100 opacity-100"
                >
                    <div
                        v-if="show"
                        ref="dialogRef"
                        role="dialog"
                        aria-modal="true"
                        :aria-labelledby="title ? titleId : undefined"
                        :aria-label="title ? undefined : 'Dialog'"
                        tabindex="-1"
                        :class="['relative w-full overflow-hidden rounded-2xl bg-white shadow-xl shadow-slate-300/40', maxWidthClass[maxWidth]]"
                    >
                        <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                            <h3 :id="titleId" class="text-base font-semibold text-slate-800">{{ title }}</h3>
                            <button
                                type="button"
                                aria-label="Tutup dialog"
                                class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                                @click="close"
                            >
                                <Icon name="close" class="h-5 w-5" aria-hidden="true" />
                            </button>
                        </div>

                        <div class="max-h-[70vh] overflow-y-auto px-6 py-5">
                            <slot />
                        </div>

                        <div v-if="$slots.footer" class="flex items-center justify-end gap-3 border-t border-slate-100 bg-slate-50/60 px-6 py-4">
                            <slot name="footer" />
                        </div>
                    </div>
                </transition>
            </div>
        </transition>
    </teleport>
</template>
