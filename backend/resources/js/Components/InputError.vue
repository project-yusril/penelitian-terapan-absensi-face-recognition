<script setup>
defineProps({
    message: { type: String, default: null },
});
</script>

<template>
    <p v-if="message" class="mt-1 text-xs font-medium text-rose-500">{{ message }}</p>
</template>
