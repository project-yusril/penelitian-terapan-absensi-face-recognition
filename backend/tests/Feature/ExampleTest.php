<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Root URL ('/') seharusnya me-redirect tamu ke halaman login dashboard.
     * Lihat routes/web.php — `Route::get('/', fn () => redirect()->route('login'))`.
     */
    public function test_root_redirects_guest_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
