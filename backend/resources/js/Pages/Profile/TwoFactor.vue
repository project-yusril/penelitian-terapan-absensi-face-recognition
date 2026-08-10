<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import InputError from '@/Components/InputError.vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps({
    enabled: { type: Boolean, default: false },
    has_pending_secret: { type: Boolean, default: false },
    qr_svg: { type: String, default: null },
    secret: { type: String, default: null },
});

const setupForm = useForm({});
const confirmForm = useForm({ code: '' });
const disableForm = useForm({ current_password: '' });

const generate = () => setupForm.post(route('profile.2fa.setup'), { preserveScroll: true });
const confirm = () => confirmForm.post(route('profile.2fa.confirm'), { preserveScroll: true, onSuccess: () => confirmForm.reset() });
const disable = () => disableForm.post(route('profile.2fa.disable'), { preserveScroll: true, onSuccess: () => disableForm.reset() });
</script>

<template>
    <Head title="Autentikasi Dua Faktor" />
    <PageHeader title="Autentikasi Dua Faktor (2FA)" subtitle="Lapisan keamanan tambahan dengan aplikasi authenticator (TOTP)" />

    <div class="mx-auto max-w-2xl space-y-6">
        <!-- Status aktif -->
        <div v-if="enabled" class="card p-6">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                    <Icon name="check" class="h-5 w-5" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-slate-700">2FA Aktif</h3>
                    <p class="text-xs text-slate-400">Kode 6 digit diminta setiap login.</p>
                </div>
            </div>

            <form class="mt-6 space-y-4 border-t border-slate-100 pt-5" @submit.prevent="disable">
                <p class="text-sm text-slate-500">Untuk menonaktifkan, konfirmasi password Anda.</p>
                <div>
                    <label class="label">Password</label>
                    <input v-model="disableForm.current_password" type="password" class="input" required />
                    <InputError :message="disableForm.errors.current_password" />
                </div>
                <button class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700" :disabled="disableForm.processing">
                    Nonaktifkan 2FA
                </button>
            </form>
        </div>

        <!-- Belum aktif -->
        <div v-else class="card p-6">
            <h3 class="text-sm font-semibold text-slate-700">Aktifkan 2FA</h3>
            <p class="mt-1 text-xs text-slate-400">
                Gunakan aplikasi seperti Google Authenticator / Authy. Scan QR lalu masukkan kode 6 digit.
            </p>

            <div v-if="!has_pending_secret" class="mt-5">
                <button class="btn-primary" :disabled="setupForm.processing" @click="generate">
                    <Icon name="plus" class="h-4 w-4" /> Generate Secret & QR
                </button>
            </div>

            <div v-else class="mt-5 space-y-5">
                <div class="flex flex-col items-center gap-3 rounded-xl bg-slate-50 p-5">
                    <div class="rounded-lg bg-white p-3 shadow-sm" v-html="qr_svg" />
                    <p class="text-xs text-slate-400">Atau masukkan kode manual:</p>
                    <code class="rounded bg-white px-3 py-1 font-mono text-sm tracking-wider text-slate-700">{{ secret }}</code>
                </div>

                <form class="space-y-3" @submit.prevent="confirm">
                    <label class="label">Kode Verifikasi (6 digit)</label>
                    <input v-model="confirmForm.code" type="text" inputmode="numeric" maxlength="6" class="input tracking-[0.5em]" placeholder="••••••" required />
                    <InputError :message="confirmForm.errors.code" />
                    <button class="btn-primary" :disabled="confirmForm.processing">Aktifkan 2FA</button>
                </form>
            </div>
        </div>
    </div>
</template>
