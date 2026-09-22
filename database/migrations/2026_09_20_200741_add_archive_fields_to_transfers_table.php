<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->string('archive_status')->nullable();
            $table->string('archive_path')->nullable();
            $table->unsignedTinyInteger('archive_progress')->default(0);
            $table->timestamp('archive_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropColumn(['archive_status', 'archive_path', 'archive_progress', 'archive_requested_at']);
        });
    }
};
