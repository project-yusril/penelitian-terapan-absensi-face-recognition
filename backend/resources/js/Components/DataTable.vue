<script setup>
import { ref, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    // [{ key, label, sortable, align, width }]
    columns: { type: Array, required: true },
    // Laravel paginator object: { data, links, meta? , current_page, last_page, from, to, total, per_page }
    rows: { type: Object, required: true },
    // current filter state from the server
    filters: { type: Object, default: () => ({}) },
    // route name to reload (Ziggy)
    routeName: { type: String, required: true },
    searchPlaceholder: { type: String, default: 'Cari...' },
    // extra query params to always preserve (e.g. select filters)
    extraParams: { type: Object, default: () => ({}) },
});

const search = ref(props.filters.search ?? '');
const perPage = ref(props.filters.per_page ?? 10);
const sort = ref(props.filters.sort ?? null);
const direction = ref(props.filters.direction ?? 'asc');

const meta = computed(() => {
    const r = props.rows;
    // Inertia "WithQueryString" paginator exposes these at top level
    return {
        current: r.current_page ?? r.meta?.current_page ?? 1,
        last: r.last_page ?? r.meta?.last_page ?? 1,
        from: r.from ?? r.meta?.from ?? 0,
        to: r.to ?? r.meta?.to ?? 0,
        total: r.total ?? r.meta?.total ?? 0,
    };
});

const data = computed(() => props.rows.data ?? []);

let debounce = null;
const reload = (overrides = {}) => {
    const params = {
        search: search.value || undefined,
        per_page: perPage.value,
        sort: sort.value || undefined,
        direction: direction.value,
        ...props.extraParams,
        ...overrides,
    };
    router.get(route(props.routeName), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => reload({ page: 1 }), 350);
});

watch(perPage, () => reload({ page: 1 }));

const toggleSort = (col) => {
    if (!col.sortable) return;
    if (sort.value === col.key) {
        direction.value = direction.value === 'asc' ? 'desc' : 'asc';
    } else {
        sort.value = col.key;
        direction.value = 'asc';
    }
    reload({ page: 1 });
};

const goTo = (page) => {
    if (page < 1 || page > meta.value.last || page === meta.value.current) return;
    reload({ page });
};

const pages = computed(() => {
    const { current, last } = meta.value;
    const range = [];
    const start = Math.max(1, current - 2);
    const end = Math.min(last, current + 2);
    for (let i = start; i <= end; i++) range.push(i);
    return range;
});

const alignClass = (a) => (a === 'right' ? 'text-right' : a === 'center' ? 'text-center' : 'text-left');
</script>

<template>
    <div class="card overflow-hidden">
        <!-- Toolbar -->
        <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="relative w-full sm:max-w-xs">
                <Icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                    v-model="search"
                    type="text"
                    :placeholder="searchPlaceholder"
                    class="input pl-9"
                />
            </div>

            <div class="flex items-center gap-3">
                <slot name="filters" />
                <div class="flex items-center gap-2 text-sm text-slate-500">
                    <span class="hidden sm:inline">Tampil</span>
                    <select v-model.number="perPage" class="input w-auto py-2">
                        <option :value="10">10</option>
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>
                </div>
                <slot name="actions" />
            </div>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100">
                <thead>
                    <tr class="bg-slate-50/60">
                        <!-- L-07: sorting dapat diakses keyboard dan mengumumkan
                             state melalui aria-sort. -->
                        <th
                            v-for="col in columns"
                            :key="col.key"
                            scope="col"
                            :aria-sort="col.sortable
                                ? (sort === col.key ? (direction === 'asc' ? 'ascending' : 'descending') : 'none')
                                : undefined"
                            :class="[
                                'whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500',
                                alignClass(col.align),
                            ]"
                            :style="col.width ? { width: col.width } : {}"
                        >
                            <button
                                v-if="col.sortable"
                                type="button"
                                class="inline-flex items-center gap-1 select-none rounded hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                                @click="toggleSort(col)"
                            >
                                {{ col.label }}
                                <Icon
                                    v-if="sort === col.key"
                                    name="chevron-down"
                                    aria-hidden="true"
                                    :class="['h-3.5 w-3.5 transition', direction === 'asc' ? 'rotate-180' : '']"
                                />
                                <span v-else class="text-slate-300" aria-hidden="true">↕</span>
                            </button>
                            <span v-else class="inline-flex items-center gap-1">{{ col.label }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-if="data.length === 0">
                        <td :colspan="columns.length" class="px-4 py-16 text-center">
                            <div class="flex flex-col items-center gap-2 text-slate-400">
                                <Icon name="inbox" class="h-10 w-10" />
                                <p class="text-sm font-medium">Tidak ada data</p>
                            </div>
                        </td>
                    </tr>
                    <tr
                        v-for="(row, idx) in data"
                        :key="row.id ?? idx"
                        class="transition hover:bg-slate-50/70"
                    >
                        <td
                            v-for="col in columns"
                            :key="col.key"
                            :class="['whitespace-nowrap px-4 py-3 text-sm text-slate-600', alignClass(col.align)]"
                        >
                            <slot :name="`cell:${col.key}`" :row="row" :value="row[col.key]">
                                {{ row[col.key] ?? '—' }}
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Footer / pagination -->
        <div class="flex flex-col items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 sm:flex-row">
            <p class="text-sm text-slate-500">
                Menampilkan <span class="font-medium text-slate-700">{{ meta.from }}</span>–<span class="font-medium text-slate-700">{{ meta.to }}</span>
                dari <span class="font-medium text-slate-700">{{ meta.total }}</span> data
            </p>

            <nav class="flex items-center gap-1" aria-label="Navigasi halaman">
                <button
                    type="button"
                    aria-label="Halaman sebelumnya"
                    class="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 disabled:opacity-40"
                    :disabled="meta.current <= 1"
                    @click="goTo(meta.current - 1)"
                >
                    <Icon name="chevron-left" class="h-4 w-4" aria-hidden="true" />
                </button>
                <button
                    v-for="p in pages"
                    :key="p"
                    type="button"
                    :aria-label="`Halaman ${p}`"
                    :aria-current="p === meta.current ? 'page' : undefined"
                    :class="[
                        'flex h-9 min-w-9 items-center justify-center rounded-lg px-3 text-sm font-medium transition',
                        p === meta.current ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100',
                    ]"
                    @click="goTo(p)"
                >
                    {{ p }}
                </button>
                <button
                    type="button"
                    aria-label="Halaman berikutnya"
                    class="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 disabled:opacity-40"
                    :disabled="meta.current >= meta.last"
                    @click="goTo(meta.current + 1)"
                >
                    <Icon name="chevron-right" class="h-4 w-4" aria-hidden="true" />
                </button>
            </nav>
        </div>
    </div>
</template>
