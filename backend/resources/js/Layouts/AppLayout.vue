<script setup>
import { ref, computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import FlashToast from '@/Components/FlashToast.vue';
import Icon from '@/Components/Icon.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const appName = computed(() => page.props.app?.name ?? 'Absensi Mahasiswa');

const sidebarOpen = ref(false);
const userMenuOpen = ref(false);

const roleNames = computed(() => user.value?.roles ?? []);
const can = (roles) => roles.some((r) => roleNames.value.includes(r));

const unreadCount = computed(() => page.props.notifications?.unread ?? 0);


const masterRoles = ['super_admin', 'ketua_jurusan', 'admin_jurusan', 'kaprodi', 'admin_prodi'];
const kaprodiRoles = ['kaprodi', 'super_admin'];

const navGroups = computed(() => [

    {
        label: null,
        items: [
            { name: 'Dashboard', route: 'dashboard', icon: 'home', show: true },
            { name: 'Kehadiran', route: 'attendance.index', icon: 'check', show: true },
        ],
    },
    {
        label: 'Master Data',
        items: [
            { name: 'Pengguna', route: 'users.index', icon: 'users', show: can(masterRoles) },
            { name: 'Program Studi', route: 'prodi.index', icon: 'academic', show: can(masterRoles) },
            { name: 'Mata Kuliah', route: 'mata-kuliah.index', icon: 'book', show: can(masterRoles) },
            { name: 'Jadwal', route: 'jadwal.index', icon: 'calendar', show: can(masterRoles) },
            { name: 'Tahun Ajaran', route: 'tahun-ajaran.index', icon: 'calendar', show: can(masterRoles) },
            { name: 'Semester', route: 'semester.index', icon: 'book', show: can(masterRoles) },
            { name: 'Geofence', route: 'geofence.index', icon: 'academic', show: can(masterRoles) },
        ],
    },
    {
        label: 'Persetujuan',
        items: [
            { name: 'Enrollment', route: 'enrollments.index', icon: 'user', show: can(kaprodiRoles) },
            { name: 'Re-Enrollment', route: 're-enrollments.index', icon: 'user', show: can(kaprodiRoles) },
            { name: 'Izin & Sakit', route: 'leave-requests.index', icon: 'inbox', show: can(kaprodiRoles) },
        ],
    },
    {
        label: 'Dosen',
        items: [
            { name: 'Approval Kehadiran', route: 'dosen.attendance.index', icon: 'check', show: can(['dosen', 'super_admin']) },
            { name: 'Rekap Kehadiran', route: 'dosen.rekap', icon: 'book', show: can(['dosen', 'super_admin']) },

        ],
    },
    {
        label: 'SP & Laporan',
        items: [
            { name: 'Surat Peringatan', route: 'sp.index', icon: 'warning', show: can([...masterRoles, 'ketua_jurusan']) },
            { name: 'Laporan', route: 'reports.index', icon: 'book', show: can(masterRoles) },
            { name: 'Audit Trail', route: 'audit-trail.index', icon: 'clock', show: can(['super_admin', 'admin_jurusan']) },
        ],
    },
    {
        label: 'Sistem',
        items: [
            { name: 'Konfigurasi', route: 'settings.index', icon: 'edit', show: can(['super_admin', 'admin_prodi', 'kaprodi']) },
            { name: 'Mode Pengujian', route: 'test-mode.index', icon: 'search', show: can(['super_admin']) },
            { name: 'Analisis', route: 'analysis.index', icon: 'academic', show: can(['super_admin']) },
        ],
    },
].map((group) => ({
    ...group,
    items: group.items.filter((item) => item.show),
})).filter((group) => group.items.length > 0));


const isActive = (routeName) => route().current(routeName);

const initials = computed(() => {
    const name = user.value?.nama ?? '';
    return name.split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase();
});

const logout = () => router.post(route('logout'));
</script>

<template>
    <div class="min-h-screen bg-slate-50">
        <!-- Mobile sidebar overlay -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-40 bg-slate-900/30 backdrop-blur-sm lg:hidden"
            @click="sidebarOpen = false"
        />

        <!-- Sidebar -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-50 flex w-72 transform flex-col border-r border-slate-200/70 bg-white transition-transform duration-200 ease-out lg:translate-x-0',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full',
            ]"
        >
            <div class="flex h-16 shrink-0 items-center gap-3 border-b border-slate-100 px-6">
                <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-600 text-white shadow-sm shadow-brand-200">
                    <Icon name="check" class="h-5 w-5" />
                </div>
                <div class="leading-tight">
                    <p class="text-sm font-semibold text-slate-800">{{ appName }}</p>
                    <p class="text-xs text-slate-400">Dashboard Admin</p>
                </div>
            </div>

            <nav class="flex-1 space-y-5 overflow-y-auto px-4 py-5">
                <div v-for="(group, gi) in navGroups" :key="gi">
                    <p v-if="group.label" class="px-3 pb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-400">
                        {{ group.label }}
                    </p>
                    <div class="flex flex-col gap-1">
                        <Link
                            v-for="item in group.items"
                            :key="item.route"
                            :href="route(item.route)"
                            :class="[
                                'group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition',
                                isActive(item.route)
                                    ? 'bg-brand-50 text-brand-700'
                                    : 'text-slate-500 hover:bg-slate-50 hover:text-slate-700',
                            ]"
                        >
                            <Icon
                                :name="item.icon"
                                :class="['h-5 w-5', isActive(item.route) ? 'text-brand-600' : 'text-slate-400 group-hover:text-slate-500']"
                            />
                            {{ item.name }}
                        </Link>
                    </div>
                </div>
            </nav>

            <div class="shrink-0 border-t border-slate-100 p-4">
                <div class="rounded-xl bg-slate-50 p-3 text-xs text-slate-400">
                    <p class="font-medium text-slate-500">Sistem Absensi Mahasiswa</p>
                    <p>Geolocation &amp; Face Recognition</p>
                </div>
            </div>
        </aside>

        <!-- Main content -->
        <div class="lg:pl-72">
            <!-- Topbar -->
            <header class="sticky top-0 z-30 flex h-16 items-center gap-4 border-b border-slate-200/70 bg-white/80 px-4 backdrop-blur sm:px-6">
                <button
                    type="button"
                    aria-label="Buka menu navigasi"
                    :aria-expanded="sidebarOpen"
                    class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden"
                    @click="sidebarOpen = true"
                >
                    <Icon name="menu" class="h-5 w-5" aria-hidden="true" />
                </button>

                <div class="flex-1">
                    <slot name="header">
                        <h1 class="text-base font-semibold text-slate-700">{{ $page.props.title ?? '' }}</h1>
                    </slot>
                </div>

                <!-- Notifications bell -->
                <Link
                    :href="route('notifications.index')"
                    class="relative rounded-lg p-2 text-slate-500 transition hover:bg-slate-100"
                    :aria-label="unreadCount > 0 ? `Notifikasi, ${unreadCount} belum dibaca` : 'Notifikasi'"
                >
                    <Icon name="inbox" class="h-5 w-5" aria-hidden="true" />
                    <span
                        v-if="unreadCount > 0"
                        aria-hidden="true"
                        class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-semibold text-white"
                    >{{ unreadCount > 99 ? '99+' : unreadCount }}</span>
                </Link>

                <!-- User menu -->
                <div class="relative">

                    <button
                        id="user-menu-button"
                        class="flex items-center gap-3 rounded-xl py-1.5 pl-1.5 pr-3 transition hover:bg-slate-50"
                        :aria-label="`Menu akun ${user?.nama ?? ''}`.trim()"
                        aria-haspopup="menu"
                        :aria-expanded="userMenuOpen"
                        aria-controls="user-menu"
                        @click="userMenuOpen = !userMenuOpen"
                        @keydown.esc="userMenuOpen = false"
                        @blur="setTimeout(() => (userMenuOpen = false), 150)"
                    >
                        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-100 text-sm font-semibold text-brand-700">
                            {{ initials }}
                        </span>
                        <span class="hidden text-left sm:block">
                            <span class="block text-sm font-medium text-slate-700">{{ user?.nama }}</span>
                            <span class="block text-xs text-slate-400">{{ user?.role_label }}</span>
                        </span>
                        <Icon name="chevron-down" class="hidden h-4 w-4 text-slate-400 sm:block" aria-hidden="true" />
                    </button>

                    <transition
                        enter-active-class="transition duration-150 ease-out"
                        enter-from-class="opacity-0 -translate-y-1"
                        enter-to-class="opacity-100 translate-y-0"
                    >
                        <div
                            v-if="userMenuOpen"
                            id="user-menu"
                            role="menu"
                            aria-labelledby="user-menu-button"
                            class="absolute right-0 mt-2 w-56 origin-top-right rounded-xl border border-slate-200/70 bg-white p-1.5 shadow-lg shadow-slate-200/60"
                            @keydown.esc="userMenuOpen = false"
                        >
                            <div class="border-b border-slate-100 px-3 py-2">
                                <p class="truncate text-sm font-medium text-slate-700">{{ user?.nama }}</p>
                                <p class="truncate text-xs text-slate-400">{{ user?.email }}</p>
                            </div>
                            <Link
                                :href="route('profile.edit')"
                                role="menuitem"
                                class="mt-1 flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
                            >
                                <Icon name="user" class="h-4 w-4" aria-hidden="true" />
                                Profil Saya
                            </Link>
                            <button
                                role="menuitem"
                                class="mt-1 flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-rose-600 hover:bg-rose-50"
                                @mousedown.prevent="logout"
                            >
                                <Icon name="logout" class="h-4 w-4" aria-hidden="true" />
                                Keluar
                            </button>

                        </div>
                    </transition>
                </div>
            </header>

            <main class="px-4 py-6 sm:px-6 lg:px-8">
                <slot />
            </main>
        </div>

        <FlashToast />
    </div>
</template>
