<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // 1. Primero rompemos la llave foránea (MySQL lo exige)
            $table->dropForeign(['stylist_id']);
            
            // 2. Luego borramos la columna
            $table->dropColumn('stylist_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('stylist_id')->nullable()->constrained('users');
        });
    }
};