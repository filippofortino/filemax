<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const array OWNED = ['transfers', 'secrets', 'drops'];

    public function up(): void
    {
        throw_if(
            ! app()->isDownForMaintenance() && (app()->isProduction() || DB::table('users')->exists() || DB::table('teams')->exists()),
            RuntimeException::class,
            'Put all instances in maintenance mode and drain old HTTP and queue workers before migrating organizations. See README.md.',
        );

        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('logo_path')->nullable();
            $table->boolean('personal')->default(false);
            $table->timestamps();
        });

        Schema::create('organization_user', function (Blueprint $table): void {
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->timestamps();
            $table->primary(['organization_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('organization_domains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->index();
            $table->timestamps();
            $table->unique(['organization_id', 'domain']);
        });

        Schema::create('organization_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role')->default('member');
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['organization_id', 'email']);
        });

        $this->addOrganizationColumn('users', 'current_organization_id', 'set null');

        foreach (self::OWNED as $name) {
            $this->addOrganizationColumn($name, 'organization_id', 'cascade');
            Schema::table($name, fn (Blueprint $table) => $table->index('organization_id'));
        }

        $this->moveExistingData();

        DB::table('transfers')->where('visibility', 'teams')->update(['visibility' => 'members']);

        Schema::dropIfExists('team_transfer');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }

    public function down(): void
    {
        throw_if(
            DB::table('organizations')->exists() || DB::table('users')->exists(),
            RuntimeException::class,
            'Rolling back a populated organization database cannot restore the original team access lists. Restore a backup instead.',
        );

        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('team_user', function (Blueprint $table): void {
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['team_id', 'user_id']);
            $table->index('user_id');
        });
        Schema::create('team_transfer', function (Blueprint $table): void {
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('transfer_id')->constrained()->cascadeOnDelete();
            $table->primary(['team_id', 'transfer_id']);
            $table->index('transfer_id');
        });
        foreach (self::OWNED as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropForeign(['organization_id']);
                $table->dropIndex(['organization_id']);
                $table->dropColumn('organization_id');
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_organization_id');
        });
        Schema::dropIfExists('organization_invitations');
        Schema::dropIfExists('organization_domains');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }

    /**
     * The column stays nullable in the schema because making it required would rebuild the table on SQLite; the models always fill it.
     * For the same reason SQLite gets the column and its foreign key in one native statement: Laravel would rebuild the table, and
     * dropping the old copy inside the migration's transaction cascades deletes into every table that points at it.
     */
    private function addOrganizationColumn(string $table, string $column, string $onDelete): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("alter table \"{$table}\" add column \"{$column}\" varchar references \"organizations\" (\"id\") on delete {$onDelete}");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $onDelete): void {
            $foreign = $blueprint->foreignUuid($column)->nullable()->constrained('organizations');
            $onDelete === 'cascade' ? $foreign->cascadeOnDelete() : $foreign->nullOnDelete();
        });
    }

    /** Everything that existed before organizations belonged to one company: it becomes the first organization, and every user also gets a Personal one. */
    private function moveExistingData(): void
    {
        $users = DB::table('users')->get(['id', 'is_admin']);

        if ($users->isEmpty() && DB::table('teams')->doesntExist()) {
            return;
        }

        $now = now();
        $organizationId = (string) Str::uuid7();
        DB::table('organizations')->insert([
            'id' => $organizationId,
            'name' => config()->string('filemax.migration.organization_name'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $domains = collect(explode(',', config()->string('filemax.migration.allowed_email_domains')))
            ->map(fn (string $domain): string => mb_strtolower(mb_trim($domain)))
            ->filter()->unique();

        foreach ($domains as $domain) {
            DB::table('organization_domains')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $organizationId, 'domain' => $domain, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach ($users as $user) {
            $personalId = (string) Str::uuid7();
            DB::table('organizations')->insert(['id' => $personalId, 'name' => 'Personal', 'personal' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('organization_user')->insert([
                ['organization_id' => $personalId, 'user_id' => $user->id, 'role' => 'admin', 'created_at' => $now, 'updated_at' => $now],
                ['organization_id' => $organizationId, 'user_id' => $user->id, 'role' => $user->is_admin ? 'admin' : 'member', 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        // Existing users keep working where their transfers are.
        DB::table('users')->update(['current_organization_id' => $organizationId]);

        foreach (self::OWNED as $name) {
            DB::table($name)->update(['organization_id' => $organizationId]);
        }
    }
};
