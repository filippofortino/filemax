<?php

declare(strict_types=1);

return [
    'disk' => env('FILEMAX_DISK', 'local'),
    // Emails show times in this zone, since the app itself runs in UTC.
    'timezone' => env('FILEMAX_TIMEZONE', 'Europe/Rome'),
    // While closed, only an invite or an email on an organization's allowed domain can create an account.
    'signup_open' => (bool) env('FILEMAX_SIGNUP_OPEN', false),
    // Read once, by the migration that moves pre-organization data into its first organization.
    'migration' => [
        'organization_name' => env('FILEMAX_ORGANIZATION_NAME', 'Mediamax Communication'),
        'allowed_email_domains' => (string) env('FILEMAX_ALLOWED_EMAIL_DOMAINS', ''),
    ],
];
