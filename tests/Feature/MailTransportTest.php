<?php

declare(strict_types=1);

use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;

test('the default mailer resolves the Resend transport with the configured API key', function (): void {
    config([
        'mail.default' => 'resend',
        'services.resend.key' => 're_test_key',
    ]);

    expect(Mail::mailer()->getSymfonyTransport())->toBeInstanceOf(ResendTransport::class);
});
