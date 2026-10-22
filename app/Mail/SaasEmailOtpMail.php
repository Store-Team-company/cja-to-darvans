<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SaasEmailOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly string $purpose
    ) {
    }

    public function build(): static
    {
        return $this
            ->subject('Your Data Pilot verification code')
            ->view('saas.emails.otp', [
                'otp' => $this->code,
                'expiresMinutes' => 10,
            ]);
    }
}
