<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transfer_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('transfer_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path')->unique();
            $table->unsignedBigInteger('size');
            $table->string('mime_type')->default('application/octet-stream');
            $table->string('status')->default('uploading');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('download_count')->default(0);
            $table->text('upload_id')->nullable();
            $table->unsignedBigInteger('part_size')->default(5242880);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_files');
    }
};
