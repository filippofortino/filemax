<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Password::defaults(fn (): ?Password => $this->app->isProduction()
            ? Password::min(12)->mixedCase()->numbers()->symbols()
            : null);

        RateLimiter::for('drop-submissions', fn (Request $request): Limit => Limit::perHour(30)
            ->by($this->network((string) $request->ip()))
            ->response(fn (): JsonResponse => response()->json(['message' => 'Too many uploads from your network. Try again later.'], 429)));
    }

    public function register(): void
    {
        $disk = 'filesystems.disks.'.Config::string('filemax.disk', 'local');

        if (Config::get($disk.'.driver') === 's3') {
            // The SDK's automatic checksum pass rewinds nonseekable HTTP streams.
            Config::set($disk.'.response_checksum_validation', 'when_required');
        }
    }

    /** One IPv6 /64 is one network, so it shares a single rate-limit bucket; IPv4-mapped addresses stay per IPv4 address. */
    private function network(string $ip): string
    {
        $binary = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? false : inet_pton($ip);

        if ($binary === false) {
            return $ip;
        }

        if (mb_substr($binary, 0, 12, '8bit') === str_repeat("\0", 10)."\xff\xff") {
            return (string) inet_ntop(mb_substr($binary, 12, 4, '8bit'));
        }

        return (string) inet_ntop(mb_substr($binary, 0, 8, '8bit').str_repeat("\0", 8));
    }
}
