<?php

namespace Tests\Feature;

use Illuminate\Routing\Router;
use Tests\TestCase;

/**
 * Invarian matriks role (MS-01 / Milestone 2 temuan.md):
 * setiap route non-publik WAJIB dijaga guard role canonical —
 * `role:` (App\Http\Middleware\CheckRole) untuk API atau
 * `web.role:` (App\Http\Middleware\EnsureUserRole) untuk dashboard web.
 *
 * Test ini membaca tabel route langsung dari Router yang ter-boot (bukan
 * dokumen, bukan output `route:list`), sehingga route baru yang ditambahkan
 * tanpa guard role otomatis menggagalkan suite sampai route tersebut dijaga,
 * atau — bila memang sengaja publik/user-owned — dimasukkan eksplisit ke
 * allowlist test ini dengan justifikasi (lalu disinkronkan ke
 * docs/ROLE-PERMISSION-MATRIX.md).
 */
class RouteGuardInvariantTest extends TestCase
{
    /**
     * Allowlist route publik (tanpa autentikasi). Setiap entri adalah
     * keputusan keamanan yang harus dijustifikasi dan disinkronkan dengan
     * ROLE-PERMISSION-MATRIX.md.
     */
    private array $publicAllowlist = [
        // Infrastruktur/framework.
        '/' => ['GET'],
        'up' => ['GET'],
        'sanctum/csrf-cookie' => ['GET'],
        'storage/{path}' => ['GET', 'PUT'],

        // Login dashboard (guest, throttle:login).
        'login' => ['GET', 'POST'],

        // API publik: health + auth (semua throttle:login).
        'api/health' => ['GET'],
        'api/auth/login' => ['POST'],
        'api/auth/forgot-password' => ['POST'],
        'api/auth/reset-password' => ['POST'],
    ];

    /**
     * Route terautentikasi milik user itu sendiri (owner-scoped) yang memang
     * tidak memerlukan guard role. Semua di dalam group
     * `auth:sanctum + user.active + throttle:api`.
     */
    private array $authenticatedAllowlist = [
        'api/auth/logout' => ['POST'],
        'api/auth/me' => ['GET'],
        'api/auth/refresh' => ['POST'],
        'api/auth/change-password' => ['POST'],
        'api/fcm-token' => ['POST'],
        'api/profile' => ['GET', 'PUT'],
        'api/profile/foto' => ['POST'],
        'api/profile/signature' => ['POST'],
        'api/notifications' => ['GET'],
        'api/notifications/unread-count' => ['GET'],
        'api/notifications/{id}/read' => ['PUT'],
        'api/notifications/read-all' => ['PUT'],
        'api/private/enrollment-photos/{user}' => ['GET'],
        'api/private/re-enrollment-photos/{reEnrollment}' => ['GET'],
        'api/private/leave-documents/{leaveRequest}' => ['GET'],
        // FOTO ATTEMPT BERISIKO: signed URL + audit akses di controller.
        // Pemilik attendance melihat fotonya sendiri; kaprodi/admin via
        // AuthorizationService::assertCanApproveProdiResource di dalam
        // PrivateFileController@attemptFoto (lihat juga ROLE-PERMISSION-MATRIX).
        'api/private/attempt-fotos/{attendanceLog}' => ['GET'],
        'private/attempt-fotos/{attendanceLog}' => ['GET'],
    ];

    private const ROLE_GUARD_MIDDLEWARE = [
        'App\Http\Middleware\CheckRole',
        'App\Http\Middleware\EnsureUserRole',
        // Alias yang dipetakan di bootstrap/app.php.
        'role',
        'web.role',
    ];

    private const CANONICAL_ROLES = [
        'super_admin',
        'admin_jurusan',
        'admin_prodi',
        'kaprodi',
        'ketua_jurusan',
        'dosen',
        'mahasiswa',
        'orang_tua',
    ];

    public function test_setiap_route_non_publik_memiliki_guard_role(): void
    {
        $unguarded = [];

        foreach ($this->routeTable() as $uri => $entry) {
            if ($this->isAllowlisted($uri, $entry['methods'])) {
                continue;
            }

            if ($entry['guards'] === []) {
                $unguarded[] = sprintf('%s [%s]', $uri, implode('|', $entry['methods']));
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            "Ada route non-publik tanpa guard role canonical.\n".
            "Jaga route berikut dengan middleware `role:super_admin,...` (API) atau\n".
            "`web.role:...` (web), atau — bila memang sengaja publik/user-owned —\n".
            "tambahkan ke allowlist RouteGuardInvariantTest beserta justifikasi,\n".
            "lalu sinkronkan docs/ROLE-PERMISSION-MATRIX.md:\n".
            implode("\n", $unguarded)
        );
    }

    public function test_guard_role_hanya_mereferensikan_role_canonical(): void
    {
        $unknown = [];

        foreach ($this->routeTable() as $uri => $entry) {
            foreach ($entry['guardRoles'] as $role) {
                if (! in_array($role, self::CANONICAL_ROLES, true)) {
                    $unknown[] = sprintf('%s memakai role "%s" (tidak ada di RoleSeeder)', $uri, $role);
                }
            }
        }

        $this->assertSame(
            [],
            $unknown,
            "Guard role mereferensikan role di luar daftar canonical 8 role.\n".
            "Fail-closed mencegah akses liar, tetapi role typo membuat endpoint mati\n".
            "dan memcontek ROLE-PERMISSION-MATRIX.md:\n".
            implode("\n", $unknown)
        );
    }

    public function test_evaluator_invarian_bisa_menangkap_route_tanpa_guard(): void
    {
        // Sanity check: evaluator guard harus menolak route fiktif tanpa guard
        // dan menerima route yang dijaga CheckRole (bukti test ini tidak
        // lolos-kosong / always-green).
        $this->assertFalse($this->hasRoleGuard(['api', 'auth:sanctum', 'user.active']));
        $this->assertFalse($this->hasRoleGuard([]));
        $this->assertTrue($this->hasRoleGuard(['api', 'user.active', 'role:super_admin,admin_prodi']));
        $this->assertTrue($this->hasRoleGuard(['web', 'auth', 'web.role:super_admin,kaprodi']));
        $this->assertTrue($this->hasRoleGuard(['App\Http\Middleware\CheckRole:super_admin']));
        $this->assertTrue($this->hasRoleGuard(['App\Http\Middleware\EnsureUserRole:super_admin,kaprodi']));
    }

    /**
     * Tabel route terkonsolidasi per URI.
     *
     * @return array<string, array{methods: list<string>, guards: list<string>, guardRoles: list<string>}>
     */
    private function routeTable(): array
    {
        /** @var Router $router */
        $router = app(Router::class);
        $table = [];

        foreach ($router->getRoutes() as $route) {
            $uri = $route->uri();
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $guards = [];
            $guardRoles = [];

            foreach ($this->expandedMiddleware($router, $route->gatherMiddleware()) as $item) {
                [$name, $params] = array_pad(explode(':', $item, 2), 2, null);

                if (in_array($name, self::ROLE_GUARD_MIDDLEWARE, true)) {
                    $guards[] = $item;

                    foreach (explode(',', (string) $params) as $role) {
                        $role = trim((string) $role);

                        if ($role !== '') {
                            $guardRoles[] = $role;
                        }
                    }
                }
            }

            $existing = $table[$uri] ?? ['methods' => [], 'guards' => [], 'guardRoles' => []];
            $existing['methods'] = array_values(array_unique(array_merge($existing['methods'], $methods)));
            $existing['guards'] = array_values(array_unique(array_merge($existing['guards'], $guards)));
            $existing['guardRoles'] = array_values(array_unique(array_merge($existing['guardRoles'], $guardRoles)));
            $table[$uri] = $existing;
        }

        return $table;
    }

    /**
     * Resolve middleware group + alias menjadi nama class penuh
     * (mengulang resolusi yang dilakukan `route:list`).
     *
     * @param  list<string>|string  $middleware
     * @return list<string>
     */
    private function expandedMiddleware(Router $router, array|string $middleware): array
    {
        $groups = $router->getMiddlewareGroups();
        $aliases = $router->getMiddleware();
        $expanded = [];

        foreach ((array) $middleware as $item) {
            $item = (string) $item;
            [$name, $params] = array_pad(explode(':', $item, 2), 2, null);

            if (isset($groups[$name])) {
                $groupItems = $groups[$name];

                if ($params !== null) {
                    $groupItems = array_map(
                        fn (string $groupItem) => $groupItem.':'.$params,
                        $groupItems
                    );
                }

                $expanded = array_merge($expanded, $this->expandedMiddleware($router, $groupItems));

                continue;
            }

            if (isset($aliases[$name])) {
                $expanded[] = $params !== null ? $aliases[$name].':'.$params : $aliases[$name];

                continue;
            }

            $expanded[] = $item;
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @param  list<string>  $methods
     */
    private function isAllowlisted(string $uri, array $methods): bool
    {
        foreach ([$this->publicAllowlist, $this->authenticatedAllowlist] as $allowlist) {
            if (isset($allowlist[$uri]) && array_diff($methods, $allowlist[$uri]) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $middleware
     */
    private function hasRoleGuard(array $middleware): bool
    {
        foreach ($middleware as $item) {
            $name = explode(':', (string) $item, 2)[0];

            if (in_array($name, self::ROLE_GUARD_MIDDLEWARE, true)) {
                return true;
            }
        }

        return false;
    }
}
