<?php

declare(strict_types=1);

use App\Jobs\GenerateFilePreview;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_files', function (Blueprint $table): void {
            $table->string('preview_kind')->nullable();
            $table->string('preview_status')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedBigInteger('preview_count')->default(0);
        });

        Schema::table('transfers', function (Blueprint $table): void {
            $table->unsignedBigInteger('preview_count')->default(0);
            $table->timestamp('last_previewed_at')->nullable();
        });

        // Files uploaded before previews existed get them too, as long as their bytes are still kept.
        DB::table('transfer_files')
            ->join('transfers', 'transfers.id', '=', 'transfer_files.transfer_id')
            ->where('transfer_files.status', 'ready')
            ->whereNull('transfers.purged_at')
            ->whereNull('transfers.revoked_at')
            ->pluck('transfer_files.id')
            ->each(fn (string $id): mixed => dispatch(new GenerateFilePreview($id)));
    }

    public function down(): void
    {
        Schema::table('transfer_files', function (Blueprint $table): void {
            $table->dropColumn(['preview_kind', 'preview_status', 'page_count', 'preview_count']);
        });

        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropColumn(['preview_count', 'last_previewed_at']);
        });
    }
};
