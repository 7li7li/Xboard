<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('v2_order', 'items')) {
            Schema::table('v2_order', function (Blueprint $table) {
                $table->json('items')->nullable()->after('subscription_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_order', 'items')) {
            Schema::table('v2_order', function (Blueprint $table) {
                $table->dropColumn('items');
            });
        }
    }
};
