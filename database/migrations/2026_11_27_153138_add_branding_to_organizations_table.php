<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('wordmark_path')->nullable()->after('logo_path');
            $table->char('brand_color', 7)->nullable()->after('wordmark_path');
            $table->text('welcome_message')->nullable()->after('brand_color');
            $table->string('footer_text', 120)->nullable()->after('welcome_message');
            $table->string('footer_url')->nullable()->after('footer_text');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['wordmark_path', 'brand_color', 'welcome_message', 'footer_text', 'footer_url']);
        });
    }
};
