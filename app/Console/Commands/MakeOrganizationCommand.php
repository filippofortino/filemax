<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Description('Create an organization and make an existing user its first admin')]
#[Signature('filemax:organization {name : The name recipients see} {email : The registered, verified email of its first admin}')]
final class MakeOrganizationCommand extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', Str::lower(mb_trim($this->argument('email'))))->first();

        if (! $user?->hasVerifiedEmail()) {
            $this->error('Choose an existing, verified account as the first admin.');

            return self::FAILURE;
        }

        $organization = Organization::query()->create(['name' => $this->argument('name')]);
        $organization->users()->attach($user, ['role' => 'admin']);
        $this->info("Created {$organization->name} with {$user->email} as admin.");

        return self::SUCCESS;
    }
}
