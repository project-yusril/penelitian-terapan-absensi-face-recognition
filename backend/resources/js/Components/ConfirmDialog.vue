<script setup>
import Modal from '@/Components/Modal.vue';
import Icon from '@/Components/Icon.vue';

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: 'Konfirmasi' },
    message: { type: String, default: 'Apakah Anda yakin?' },
    confirmText: { type: String, default: 'Hapus' },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'cancel']);
</script>

<template>
    <Modal :show="show" max-width="md" title="" @close="emit('cancel')">
        <div class="flex flex-col items-center text-center">
            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-rose-100">
                <Icon name="warning" class="h-6 w-6 text-rose-500" />
            </div>
            <h3 class="mt-4 text-base font-semibold text-slate-800">{{ title }}</h3>
            <p class="mt-1.5 text-sm text-slate-500">{{ message }}</p>
        </div>

        <template #footer>
            <button class="btn-secondary" :disabled="processing" @click="emit('cancel')">Batal</button>
            <button class="btn-danger" :disabled="processing" @click="emit('confirm')">
                {{ processing ? 'Memproses...' : confirmText }}
            </button>
        </template>
    </Modal>
</template>
