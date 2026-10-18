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
            $table->foreignUuid('drop_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->string('sender_name', 120)->nullable();
            $table->timestamp('received_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('drop_id');
            $table->dropColumn(['sender_name', 'received_at']);
        });
    }
};
