<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Description('Explicitly grant or revoke Filemax team administration')]
#[Signature('filemax:admin {email : The registered, verified Mediamax email} {--revoke : Remove admin access}')]
final class MakeAdminCommand extends Command
{
    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::query()->where('email', Str::lower(mb_trim($email)))->first();

        if (! $user || (! $this->option('revoke') && (! $user->isEligible() || ! $user->hasVerifiedEmail()))) {
            $this->error('Choose an existing, verified Mediamax account.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
        $this->info($this->option('revoke') ? 'Admin access revoked.' : 'Admin access granted.');

        return self::SUCCESS;
    }
}
