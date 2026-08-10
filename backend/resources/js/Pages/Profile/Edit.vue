<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import InputError from '@/Components/InputError.vue';
import Icon from '@/Components/Icon.vue';
import { useWebPush } from '@/Composables/useWebPush';


const props = defineProps({
    user: { type: Object, required: true },
});

// Notifikasi push browser (Web Push / VAPID).
const webPush = useWebPush();
const toggleWebPush = async () => {
    if (webPush.isSubscribed.value) {
        await webPush.unsubscribe();
    } else {
        await webPush.subscribe();
    }
};


const profile = useForm({
    nama: props.user.nama ?? '',
    email: props.user.email ?? '',
    no_telp: props.user.no_telp ?? '',
});

const password = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submitProfile = () => profile.put(route('profile.update'), { preserveScroll: true });
const submitPassword = () => password.put(route('profile.password'), {
    preserveScroll: true,
    onSuccess: () => password.reset(),
});
</script>

<template>
    <Head title="Profil Saya" />
    <PageHeader title="Profil Saya" subtitle="Kelola informasi akun & password Anda" />

    <div class="grid gap-6 lg:grid-cols-3">
        <!-- Info kanan: ringkasan akun -->
        <div class="card p-5 lg:col-span-1">
            <h3 class="text-sm font-semibold text-slate-700">Informasi Akun</h3>
            <p class="mt-1 text-xs text-slate-400">Data ringkas yang dikelola oleh admin sistem.</p>

            <dl class="mt-5 space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-400">Peran</dt>
                    <dd class="font-medium text-slate-700">{{ user.role_label }}</dd>
                </div>
                <div v-if="user.prodi" class="flex justify-between">
                    <dt class="text-slate-400">Program Studi</dt>
                    <dd class="font-medium text-slate-700">{{ user.prodi.kode }}</dd>
                </div>
                <div v-if="user.nidn" class="flex justify-between">
                    <dt class="text-slate-400">NIDN</dt>
                    <dd class="font-mono text-slate-700">{{ user.nidn }}</dd>
                </div>
                <div v-if="user.nim" class="flex justify-between">
                    <dt class="text-slate-400">NIM</dt>
                    <dd class="font-mono text-slate-700">{{ user.nim }}</dd>
                </div>
                <div v-if="user.kelas" class="flex justify-between">
                    <dt class="text-slate-400">Kelas</dt>
                    <dd class="font-medium text-slate-700">{{ user.kelas }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-400">Status</dt>
                    <dd class="font-medium text-slate-700 capitalize">{{ user.status }}</dd>
                </div>
                <div v-if="user.last_login" class="flex justify-between">
                    <dt class="text-slate-400">Login terakhir</dt>
                    <dd class="text-slate-700">{{ user.last_login }}</dd>
                </div>
            </dl>
        </div>

        <!-- Form profil + password -->
        <div class="space-y-6 lg:col-span-2">
            <form class="card p-5" @submit.prevent="submitProfile">
                <h3 class="text-sm font-semibold text-slate-700">Data Pribadi</h3>
                <p class="mb-5 text-xs text-slate-400">Perubahan akan dicatat di Audit Trail.</p>

                <div class="space-y-4">
                    <div>
                        <label class="label">Nama lengkap</label>
                        <input v-model="profile.nama" class="input" type="text" required />
                        <InputError :message="profile.errors.nama" />
                    </div>
                    <div>
                        <label class="label">Email</label>
                        <input v-model="profile.email" class="input" type="email" required />
                        <InputError :message="profile.errors.email" />
                    </div>
                    <div>
                        <label class="label">No. Telepon</label>
                        <input v-model="profile.no_telp" class="input" type="tel" placeholder="08…" />
                        <InputError :message="profile.errors.no_telp" />
                    </div>
                </div>

                <div class="mt-5 flex justify-end">
                    <button class="btn-primary" :disabled="profile.processing">Simpan Perubahan</button>
                </div>
            </form>

            <form class="card p-5" @submit.prevent="submitPassword">
                <h3 class="text-sm font-semibold text-slate-700">Ganti Password</h3>
                <p class="mb-5 text-xs text-slate-400">Minimal 8 karakter & harus berbeda dari password lama.</p>

                <div class="space-y-4">
                    <div>
                        <label class="label">Password lama</label>
                        <input v-model="password.current_password" class="input" type="password" required />
                        <InputError :message="password.errors.current_password" />
                    </div>
                    <div>
                        <label class="label">Password baru</label>
                        <input v-model="password.password" class="input" type="password" required minlength="8" />
                        <InputError :message="password.errors.password" />
                    </div>
                    <div>
                        <label class="label">Konfirmasi password baru</label>
                        <input v-model="password.password_confirmation" class="input" type="password" required minlength="8" />
                    </div>
                </div>

                <div class="mt-5 flex justify-end">
                    <button class="btn-primary" :disabled="password.processing">Ubah Password</button>
                </div>
            </form>

            <!-- Pintasan Autentikasi Dua Faktor (2FA) -->
            <Link :href="route('profile.2fa')" class="card flex items-center justify-between p-5 transition hover:shadow-md">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                        <Icon name="lock" class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-slate-700">Autentikasi Dua Faktor (2FA)</h3>
                        <p class="text-xs text-slate-400">
                            {{ user.two_factor_enabled ? 'Aktif — kelola atau nonaktifkan' : 'Belum aktif — tingkatkan keamanan akun' }}
                        </p>
                    </div>
                </div>
                <span :class="['rounded-full px-3 py-1 text-xs font-medium', user.two_factor_enabled ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500']">
                    {{ user.two_factor_enabled ? 'Aktif' : 'Nonaktif' }}
                </span>
            </Link>

            <!-- Notifikasi Push Browser (Web Push) -->
            <div class="card p-5">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                            <Icon name="bell" class="h-5 w-5" />
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-slate-700">Notifikasi Browser</h3>
                            <p class="text-xs text-slate-400">
                                Terima pemberitahuan langsung di browser ini, meski tab dashboard tertutup.
                            </p>
                        </div>
                    </div>
                    <span :class="['rounded-full px-3 py-1 text-xs font-medium', webPush.isSubscribed.value ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500']">
                        {{ webPush.isSubscribed.value ? 'Aktif' : 'Nonaktif' }}
                    </span>
                </div>

                <p v-if="!webPush.isAvailable.value" class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-600">
                    Browser tidak mendukung notifikasi push, atau kunci VAPID belum dikonfigurasi pada server.
                </p>
                <p v-if="webPush.error.value" class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-600">
                    {{ webPush.error.value }}
                </p>

                <div class="mt-5 flex flex-wrap gap-3">
                    <button
                        type="button"
                        class="btn-primary"
                        :disabled="!webPush.isAvailable.value || webPush.loading.value"
                        @click="toggleWebPush"
                    >
                        {{ webPush.isSubscribed.value ? 'Nonaktifkan' : 'Aktifkan' }} Notifikasi
                    </button>
                    <button
                        v-if="webPush.isSubscribed.value"
                        type="button"
                        class="btn-secondary"
                        :disabled="webPush.loading.value"
                        @click="webPush.sendTest()"
                    >
                        Kirim Notifikasi Uji
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>



