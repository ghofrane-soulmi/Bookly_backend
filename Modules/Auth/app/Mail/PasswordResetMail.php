<?php

namespace Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $resetUrl) {}

    public function build(): self
    {
        return $this
            ->subject('Reset your password')
            ->view('auth::emails.password-reset', [
                'resetUrl' => $this->resetUrl,
            ]);
    }
}
