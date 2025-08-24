<?php
namespace App\Services;

use App\Models\User;
use Carbon\Carbon;


class AccountActionService
{
    public function __construct(
        private TokenService $tokens,
        private MailService $mailer
    ) {}

    public function sendVerification(User $user): void
    {
        $plain = $this->tokens->issue('email_verify', $user->id, 3600); // 1 jam
        $verifyUrl = rtrim($_ENV['APP_URL'] ?? '', '/') . '/auth/verify?token=' . $plain;
        $this->mailer->send($user->email, 'Verifikasi Email', "Klik tautan: {$verifyUrl}");
    }

    public function verify(string $token, User $user): bool
    {
        $tok = $this->tokens->verify('email_verify', $user->id, $token);
        if (!$tok) return false;
        $this->tokens->revoke($tok);
        $user->email_verified_at = Carbon::now();
        $user->save();
        return true;
    }

    public function sendReset(User $user): void
    {
        $plain = $this->tokens->issue('password_reset', $user->id, 3600); // 1 jam
        $url = rtrim($_ENV['APP_URL'] ?? '', '/') . '/auth/reset-password?token=' . $plain;
        $this->mailer->send($user->email, 'Reset Password', "Reset password: {$url}");
    }

    public function resetPassword(string $token, User $user, string $newPassword): bool
    {
        $tok = $this->tokens->verify('password_reset', $user->id, $token);
        if (!$tok) return false;
        $this->tokens->revoke($tok);
        $user->password = password_hash($newPassword, PASSWORD_BCRYPT);
        $user->save();
        return true;
    }
}
