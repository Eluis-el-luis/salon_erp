<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('advances', function (Blueprint $table) {
            // 1. Hacemos que el empleado sea opcional (nullable)
            $table->unsignedBigInteger('user_id')->nullable()->change();
            
            // 2. Agregamos el cliente (también opcional)
            $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('cascade')->after('user_id');
            
            // 3. Agregamos si es un Cargo (Debe) o un Abono (Haber)
            $table->enum('type', ['debe', 'haber'])->default('debe')->after('amount');
        });
    }

    public function down()
    {
        Schema::table('advances', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropColumn(['client_id', 'type']);
        });
    }
};
