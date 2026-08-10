import { ref, computed, onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'


/**
 * Composable Web Push untuk dashboard.
 *
 * Alur:
 * 1. Register service worker (/sw.js).
 * 2. Minta izin notifikasi → subscribe ke Push Service pakai VAPID public key.
 * 3. Kirim subscription ke backend (POST /push-subscriptions).
 *
 * VAPID public key di-share lewat Inertia props (`webpush.vapid_public_key`).
 */
export function useWebPush() {
    const page = usePage()

    const isSupported = ref(false)
    const permission = ref('default')
    const isSubscribed = ref(false)
    const loading = ref(false)
    const error = ref(null)

    const vapidPublicKey = computed(() => page.props?.webpush?.vapid_public_key || null)

    // Tersedia jika browser mendukung & VAPID key sudah dikonfigurasi server.
    const isAvailable = computed(() => isSupported.value && !!vapidPublicKey.value)

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
        const rawData = window.atob(base64)
        const outputArray = new Uint8Array(rawData.length)
        for (let i = 0; i < rawData.length; i++) {
            outputArray[i] = rawData.charCodeAt(i)
        }
        return outputArray
    }

    // Ambil token CSRF dari cookie XSRF-TOKEN (di-set Laravel).
    function csrfToken() {
        const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
        return match ? decodeURIComponent(match[1]) : ''
    }

    // Helper request JSON dengan kredensial + header CSRF (tanpa axios).
    async function request(method, url, body) {
        const res = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: body ? JSON.stringify(body) : undefined,
        })

        if (!res.ok) {
            throw new Error(`Permintaan gagal (${res.status})`)
        }

        return res
    }

    async function getRegistration() {
        return navigator.serviceWorker.register('/sw.js')
    }


    async function refreshState() {
        if (!isSupported.value) return
        permission.value = Notification.permission
        try {
            const reg = await navigator.serviceWorker.getRegistration('/sw.js')
            const sub = reg ? await reg.pushManager.getSubscription() : null
            isSubscribed.value = !!sub
        } catch (e) {
            isSubscribed.value = false
        }
    }

    async function subscribe() {
        error.value = null

        if (!isAvailable.value) {
            error.value = 'Web Push tidak tersedia atau belum dikonfigurasi server.'
            return false
        }

        loading.value = true
        try {
            permission.value = await Notification.requestPermission()
            if (permission.value !== 'granted') {
                error.value = 'Izin notifikasi ditolak.'
                return false
            }

            const reg = await getRegistration()
            await navigator.serviceWorker.ready

            let sub = await reg.pushManager.getSubscription()
            if (!sub) {
                sub = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey.value),
                })
            }

            const raw = sub.toJSON()
            await request('POST', '/push-subscriptions', {
                endpoint: raw.endpoint,
                keys: raw.keys,
                contentEncoding:
                    (PushManager.supportedContentEncodings &&
                        PushManager.supportedContentEncodings[0]) ||
                    'aesgcm',
            })


            isSubscribed.value = true
            return true
        } catch (e) {
            error.value = e?.message || 'Gagal mengaktifkan notifikasi.'
            return false
        } finally {
            loading.value = false
        }
    }

    async function unsubscribe() {
        error.value = null
        loading.value = true
        try {
            const reg = await navigator.serviceWorker.getRegistration('/sw.js')
            const sub = reg ? await reg.pushManager.getSubscription() : null

            if (sub) {
                const endpoint = sub.endpoint
                await sub.unsubscribe()
                await request('DELETE', '/push-subscriptions', { endpoint })

            }

            isSubscribed.value = false
            return true
        } catch (e) {
            error.value = e?.message || 'Gagal menonaktifkan notifikasi.'
            return false
        } finally {
            loading.value = false
        }
    }

    async function sendTest() {
        await request('POST', '/push-subscriptions/test')
    }


    onMounted(() => {
        isSupported.value =
            'serviceWorker' in navigator &&
            'PushManager' in window &&
            'Notification' in window
        refreshState()
    })

    return {
        isSupported,
        isAvailable,
        permission,
        isSubscribed,
        loading,
        error,
        subscribe,
        unsubscribe,
        sendTest,
        refreshState,
    }
}
