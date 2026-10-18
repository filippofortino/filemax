<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drops', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 16)->unique();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->timestamp('closes_at')->index();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedTinyInteger('keep_days');
            $table->unsignedBigInteger('max_file_size')->nullable();
            $table->unsignedBigInteger('max_submission_size')->nullable();
            $table->json('file_types')->nullable();
            $table->json('extensions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drops');
    }
};
