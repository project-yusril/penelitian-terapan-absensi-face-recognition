<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const filter = ref(props.filters.filter ?? 'all');
const setFilter = (f) => {
    filter.value = f;
    router.get(route('notifications.index'), { filter: f }, { preserveState: true, preserveScroll: true, replace: true });
};

const markRead = (n) => router.put(route('notifications.read', n.id), {}, { preserveScroll: true });
const markAll = () => router.put(route('notifications.read-all'), {}, { preserveScroll: true });

const goPage = (url) => url && router.get(url, {}, { preserveState: true, preserveScroll: true });

const typeStyle = (t) => ({
    sp: 'bg-rose-50 text-rose-500',
    enrollment: 'bg-brand-50 text-brand-500',
    leave: 'bg-sky-50 text-sky-500',
    attendance: 'bg-emerald-50 text-emerald-500',
}[t] ?? 'bg-slate-100 text-slate-500');
</script>

<template>
    <Head title="Notifikasi" />
    <PageHeader title="Notifikasi" subtitle="Pemberitahuan sistem untuk akun Anda">
        <template #actions>
            <button class="btn-secondary" @click="markAll"><Icon name="check" class="h-4 w-4" /> Tandai semua dibaca</button>
        </template>
    </PageHeader>

    <div class="mb-5 flex gap-1 rounded-xl bg-slate-100 p-1 sm:w-64">
        <button :class="['flex-1 rounded-lg px-4 py-2 text-sm font-medium transition', filter === 'all' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500']" @click="setFilter('all')">Semua</button>
        <button :class="['flex-1 rounded-lg px-4 py-2 text-sm font-medium transition', filter === 'unread' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500']" @click="setFilter('unread')">Belum Dibaca</button>
    </div>

    <div class="card divide-y divide-slate-100">
        <div v-if="items.data.length === 0" class="p-12 text-center text-slate-400">
            <Icon name="inbox" class="mx-auto h-10 w-10" />
            <p class="mt-2 text-sm">Tidak ada notifikasi.</p>
        </div>
        <div
            v-for="n in items.data"
            :key="n.id"
            :class="['flex items-start gap-4 p-4 transition', n.is_read ? '' : 'bg-brand-50/40']"
        >
            <div :class="['flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', typeStyle(n.type)]">
                <Icon name="inbox" class="h-5 w-5" />
            </div>
            <div class="flex-1">
                <div class="flex items-center gap-2">
                    <p class="text-sm font-medium text-slate-700">{{ n.title }}</p>
                    <span v-if="!n.is_read" class="h-2 w-2 rounded-full bg-brand-500" />
                </div>
                <p class="mt-0.5 text-sm text-slate-500">{{ n.body }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ n.created_at }}</p>
            </div>
            <button v-if="!n.is_read" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-brand-600" title="Tandai dibaca" @click="markRead(n)">
                <Icon name="check" class="h-4 w-4" />
            </button>
        </div>
    </div>

    <!-- Simple pagination -->
    <div v-if="items.last_page > 1" class="mt-4 flex justify-center gap-1">
        <button
            v-for="link in items.links"
            :key="link.label"
            :disabled="!link.url"
            :class="[
                'flex h-9 min-w-9 items-center justify-center rounded-lg px-3 text-sm transition',
                link.active ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 disabled:opacity-40',
            ]"
            @click="goPage(link.url)"
            v-html="link.label"
        />
    </div>
</template>
