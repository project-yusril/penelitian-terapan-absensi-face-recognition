<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    public function toMail($notifiable): MailMessage
    {
        $broker = config('auth.defaults.passwords');

        return (new MailMessage)
            ->subject('Reset Password Absensi Mahasiswa')
            ->greeting('Halo '.$notifiable->nama.',')
            ->line('Kami menerima permintaan reset password untuk akun Anda.')
            ->line('Masukkan token berikut pada halaman Lupa Password di aplikasi:')
            ->line($this->token)
            ->line('Token ini berlaku selama '.config("auth.passwords.{$broker}.expire").' menit dan hanya dapat digunakan satu kali.')
            ->line('Abaikan email ini jika Anda tidak meminta reset password.');
    }
}
