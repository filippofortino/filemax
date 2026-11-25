<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_files', function (Blueprint $table): void {
            $table->string('folder', 1024)->nullable()->after('original_name');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_files', function (Blueprint $table): void {
            $table->dropColumn('folder');
        });
    }
};
