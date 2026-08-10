<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'Absensi Mahasiswa') }}</title>

    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📋</text></svg>">

    {{-- Bersihkan service worker & cache peninggalan SPA dashboard lama.
         Dashboard sekarang memakai Inertia (SSR), tidak butuh service worker.
         Tanpa ini, SW lama bisa mem-bypass server & memunculkan respons API
         lama (mis. /attendance ter-cache jadi JSON users). --}}
    <script>
        (function () {
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.getRegistrations()
                    .then(function (regs) {
                        var hadOld = regs.length > 0;
                        regs.forEach(function (r) { r.unregister(); });
                        if (window.caches && typeof caches.keys === 'function') {
                            caches.keys().then(function (keys) {
                                keys.forEach(function (k) { caches.delete(k); });
                            });
                        }
                        // Reload sekali agar lepas dari kontrol SW lama.
                        if (hadOld && !sessionStorage.getItem('sw_cleared')) {
                            sessionStorage.setItem('sw_cleared', '1');
                            window.location.reload();
                        }
                    })
                    .catch(function () {});
            }
        })();
    </script>

    @routes

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full bg-slate-50 font-sans text-slate-700 antialiased">
    @inertia
</body>
</html>
