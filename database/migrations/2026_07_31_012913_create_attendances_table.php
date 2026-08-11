<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            // Vinculamos el registro con el empleado
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            
            // Los campos que nos pedía el Excel
            $table->date('date');
            $table->time('time_in');
            $table->boolean('aseo')->default(true);
            $table->boolean('uniforme')->default(true);
            
            $table->timestamps();

            //Un empleado solo puede tener un registro de entrada por día
            $table->unique(['user_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
