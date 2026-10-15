<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secrets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 32)->unique();
            $table->string('label', 120)->nullable();
            $table->mediumText('payload')->nullable();
            $table->string('verifier_hash', 64);
            $table->unsignedTinyInteger('max_views');
            $table->unsignedTinyInteger('view_count')->default(0);
            $table->timestamp('last_opened_at')->nullable();
            $table->unsignedInteger('wrong_key_count')->default(0);
            $table->timestamp('last_wrong_key_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secrets');
    }
};
