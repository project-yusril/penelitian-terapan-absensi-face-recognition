<script setup>
import { ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';
import InputError from '@/Components/InputError.vue';

defineOptions({ layout: null });

const page = usePage();
const showPassword = ref(false);

const form = useForm({
    login: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login.store'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Masuk" />

    <div class="flex min-h-screen items-stretch bg-slate-50">
        <!-- Left brand panel -->
        <div class="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-gradient-to-br from-brand-600 via-brand-600 to-brand-800 p-12 text-white lg:flex">
            <div class="absolute -right-16 -top-16 h-64 w-64 rounded-full bg-white/10" />
            <div class="absolute -bottom-24 -left-10 h-72 w-72 rounded-full bg-white/5" />

            <div class="relative flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/15 backdrop-blur">
                    <Icon name="check" class="h-6 w-6" />
                </div>
                <span class="text-lg font-semibold">{{ page.props.app?.name ?? 'Absensi Mahasiswa' }}</span>
            </div>

            <div class="relative max-w-md">
                <h2 class="text-3xl font-semibold leading-tight">Sistem Absensi Mahasiswa Berbasis Mobile</h2>
                <p class="mt-4 text-brand-100">
                    Geolocation &amp; Face Recognition (MobileFaceNet) — Program Studi DIII Teknik Informatika,
                    Jurusan Teknik Elektro, Politeknik Negeri Pontianak.
                </p>
            </div>

            <div class="relative flex gap-6 text-sm text-brand-100">
                <div>
                    <p class="text-2xl font-semibold text-white">Akurat</p>
                    <p>Verifikasi wajah</p>
                </div>
                <div>
                    <p class="text-2xl font-semibold text-white">Real-time</p>
                    <p>Geofencing</p>
                </div>
                <div>
                    <p class="text-2xl font-semibold text-white">Aman</p>
                    <p>Anti-spoofing</p>
                </div>
            </div>
        </div>

        <!-- Right form panel -->
        <div class="flex w-full items-center justify-center p-6 lg:w-1/2">
            <div class="w-full max-w-sm">
                <div class="mb-8 lg:hidden">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-600 text-white">
                            <Icon name="check" class="h-6 w-6" />
                        </div>
                        <span class="text-lg font-semibold text-slate-800">{{ page.props.app?.name ?? 'Absensi Mahasiswa' }}</span>
                    </div>
                </div>

                <h1 class="text-2xl font-semibold text-slate-800">Selamat datang 👋</h1>
                <p class="mt-1.5 text-sm text-slate-400">Masuk ke dashboard admin untuk melanjutkan.</p>

                <div class="mt-5 rounded-lg border border-brand-100 bg-brand-50 px-4 py-3 text-sm text-slate-600">
                    <p class="font-medium text-slate-700">Akun Super Admin</p>
                    <p class="mt-1">Email: <span class="font-mono">administrator@gmail.com</span></p>
                    <p>Password: <span class="font-mono">12345678</span></p>
                </div>

                <form class="mt-8 space-y-5" @submit.prevent="submit">
                    <div>
                        <label class="label" for="login">Email atau NIM</label>
                        <input
                            id="login"
                            v-model="form.login"
                            type="text"
                            class="input"
                            placeholder="administrator@gmail.com"
                            autofocus
                            autocomplete="username"
                        />
                        <InputError :message="form.errors.login" />
                    </div>

                    <div>
                        <label class="label" for="password">Kata Sandi</label>
                        <div class="relative">
                            <input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                class="input pr-11"
                                placeholder="••••••••"
                                autocomplete="current-password"
                            />
                            <button
                                type="button"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-medium text-slate-400 hover:text-slate-600"
                                @click="showPassword = !showPassword"
                            >
                                {{ showPassword ? 'Sembunyikan' : 'Lihat' }}
                            </button>
                        </div>
                        <InputError :message="form.errors.password" />
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-500">
                        <input v-model="form.remember" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-400" />
                        Ingat saya
                    </label>

                    <button type="submit" class="btn-primary w-full" :disabled="form.processing">
                        {{ form.processing ? 'Memproses...' : 'Masuk' }}
                    </button>
                </form>

                <p class="mt-8 text-center text-xs text-slate-400">
                    © {{ new Date().getFullYear() }} Politeknik Negeri Pontianak
                </p>
            </div>
        </div>
    </div>
</template>
