<?php
namespace App\Services;

class MailService
{
    public function __construct(private string $fromName, private string $fromEmail) {}

    public function send(string $toEmail, string $subject, string $body): void
    {
        // DEV ONLY: kirim ke log agar terlihat di console/server log
        error_log("[MAIL] To: {$toEmail} | Subj: {$subject} | Body: {$body}");
    }
}
