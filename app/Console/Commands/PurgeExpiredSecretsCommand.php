<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Secret;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Delete the ciphertext of expired secrets')]
#[Signature('filemax:purge-secrets')]
final class PurgeExpiredSecretsCommand extends Command
{
    public function handle(): int
    {
        $count = Secret::query()->whereNotNull('payload')->where('expires_at', '<=', now())->update(['payload' => null]);

        $this->components->info("Purged {$count} expired secrets.");

        return self::SUCCESS;
    }
}
