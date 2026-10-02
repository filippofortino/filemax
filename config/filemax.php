<?php

declare(strict_types=1);

return [
    'disk' => env('FILEMAX_DISK', 'local'),
    'allowed_email_domains' => array_values(array_filter(array_map(
        fn (string $domain): string => mb_strtolower(mb_trim($domain)),
        explode(',', (string) env('FILEMAX_ALLOWED_EMAIL_DOMAINS', '')),
    ))),
];
