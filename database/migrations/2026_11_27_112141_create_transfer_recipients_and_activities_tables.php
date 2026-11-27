<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** People a transfer was emailed to, and what identified people (recipients, organization members) did with it. */
    public function up(): void
    {
        Schema::create('transfer_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('transfer_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->unique(['transfer_id', 'email']);
        });

        Schema::create('transfer_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('transfer_file_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('folder', 1024)->nullable();
            $table->string('kind');
            $table->foreignUuid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('transfer_recipient_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->index(['transfer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_activities');
        Schema::dropIfExists('transfer_recipients');
    }
};
