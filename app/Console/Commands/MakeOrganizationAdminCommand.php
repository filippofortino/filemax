<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Description('Promote a verified member to admin of an existing shared organization')]
#[Signature('filemax:organization-admin {organization : The organization UUID} {email : The verified email of an existing member}')]
final class MakeOrganizationAdminCommand extends Command
{
    public function handle(): int
    {
        $id = $this->argument('organization');
        $organization = Str::isUuid($id) ? Organization::query()->where('personal', false)->find($id) : null;

        if (! $organization) {
            $this->error('Choose an existing shared organization by UUID.');

            return self::FAILURE;
        }

        $member = $organization->users()->where('email', Str::lower(mb_trim($this->argument('email'))))->first();
        if (! $member?->hasVerifiedEmail()) {
            $this->error('Choose a verified member of this organization.');

            return self::FAILURE;
        }

        $organization->users()->updateExistingPivot($member->id, ['role' => 'admin']);
        $this->info("{$member->email} is an admin of {$organization->name}.");

        return self::SUCCESS;
    }
}
