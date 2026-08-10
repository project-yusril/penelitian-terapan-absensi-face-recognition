<?php

namespace Tests\Feature;

use App\Support\SafeErrorMessage;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * Regression M-22: pesan exception mentah tidak boleh sampai ke pengguna.
 */
class SafeErrorMessageTest extends TestCase
{
    public function test_query_exception_details_are_not_exposed(): void
    {
        $exception = new QueryException(
            'mysql',
            "insert into `users` (`email`, `password`) values ('rahasia@test.com', 'hash')",
            [],
            new \RuntimeException("Duplicate entry 'rahasia@test.com' for key 'users_email_unique'")
        );

        $message = SafeErrorMessage::forDisplay($exception, 'Data tidak dapat diproses.');

        $this->assertStringContainsString('Data tidak dapat diproses.', $message);
        $this->assertStringNotContainsString('rahasia@test.com', $message);
        $this->assertStringNotContainsString('insert into', $message);
        $this->assertStringNotContainsString('users_email_unique', $message);
        $this->assertMatchesRegularExpression('/ref: [0-9a-f]{16}/', $message);
    }

    public function test_generic_exception_message_is_replaced_by_fallback(): void
    {
        $exception = new \RuntimeException('C:\\secret\\path\\config.php line 42 token=abc123');

        $message = SafeErrorMessage::forDisplay($exception, 'Gagal membaca file import.');

        $this->assertStringNotContainsString('token=abc123', $message);
        $this->assertStringNotContainsString('secret', $message);
        $this->assertStringStartsWith('Gagal membaca file import.', $message);
    }
}
