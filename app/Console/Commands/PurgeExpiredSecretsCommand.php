<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Secret;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Delete the ciphertext of expired or fully opened secrets')]
#[Signature('filemax:purge-secrets')]
final class PurgeExpiredSecretsCommand extends Command
{
    public function handle(): int
    {
        $count = Secret::query()
            ->whereNotNull('payload')
            ->where(fn ($query) => $query->where('expires_at', '<=', now())->orWhereColumn('view_count', '>=', 'max_views'))
            ->update(['payload' => null]);

        $this->components->info("Purged {$count} expired or opened secrets.");

        return self::SUCCESS;
    }
}
