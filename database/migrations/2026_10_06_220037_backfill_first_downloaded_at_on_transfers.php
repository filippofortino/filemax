<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('transfers')->whereNull('first_downloaded_at')->whereNotNull('last_downloaded_at')
            ->update(['first_downloaded_at' => DB::raw('last_downloaded_at')]);
    }

    /**
     * Historical download markers cannot be distinguished safely during rollback.
     */
    public function down(): void {}
};
