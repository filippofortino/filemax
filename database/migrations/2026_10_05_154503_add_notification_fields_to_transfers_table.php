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
            $table->timestamp('first_downloaded_at')->nullable();
            $table->timestamp('expiry_reminder_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropColumn(['first_downloaded_at', 'expiry_reminder_sent_at']);
        });
    }
};
