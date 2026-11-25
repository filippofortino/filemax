<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One zip per folder of a transfer. The archive_* columns mirror the transfer's own whole-transfer archive fields. */
    public function up(): void
    {
        Schema::create('transfer_archives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('transfer_id')->constrained()->cascadeOnDelete();
            $table->string('folder', 1024);
            $table->string('archive_status')->nullable();
            $table->string('archive_path')->nullable();
            $table->unsignedTinyInteger('archive_progress')->default(0);
            $table->timestamp('archive_requested_at')->nullable();
            $table->unsignedBigInteger('download_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_archives');
    }
};
