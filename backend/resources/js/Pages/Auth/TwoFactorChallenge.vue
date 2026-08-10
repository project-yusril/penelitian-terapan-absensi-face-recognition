<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';

const form = useForm({ code: '' });
const verify = () => form.post(route('two-factor.verify'));
const logout = () => router.post(route('logout'));
</script>

<template>
    <Head title="Verifikasi 2FA" />
    <div class="flex min-h-screen items-center justify-center bg-slate-50 px-4">
        <div class="w-full max-w-sm">
            <div class="mb-6 text-center">
                <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-600 text-white">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                </div>
                <h1 class="text-lg font-semibold text-slate-800">Verifikasi Dua Faktor</h1>
                <p class="mt-1 text-sm text-slate-400">Masukkan kode 6 digit dari aplikasi authenticator Anda.</p>
            </div>

            <form class="card space-y-4 p-6" @submit.prevent="verify">
                <div>
                    <label class="label">Kode Verifikasi</label>
                    <input
                        v-model="form.code"
                        type="text"
                        inputmode="numeric"
                        maxlength="6"
                        class="input text-center text-lg tracking-[0.5em]"
                        placeholder="••••••"
                        autofocus
                        required
                    />
                    <InputError :message="form.errors.code" />
                </div>
                <button class="btn-primary w-full justify-center" :disabled="form.processing">
                    {{ form.processing ? 'Memverifikasi...' : 'Verifikasi' }}
                </button>
                <button type="button" class="w-full text-center text-xs text-slate-400 hover:text-slate-600" @click="logout">
                    Keluar
                </button>
            </form>
        </div>
    </div>
</template>
