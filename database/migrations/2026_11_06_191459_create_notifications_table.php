<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('type'); // ej: 'venta_pendiente_aprobacion', 'caja_sin_cerrar', 'cxp_vencida', 'stock_critico'
            $table->string('title');
            $table->text('message')->nullable();
            $table->json('data')->nullable(); // datos extra: sale_id, monto, url, etc.
            $table->string('priority', 20)->default('normal'); // low, normal, high, urgent
            $table->boolean('read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read', 'created_at']);
            $table->index(['type', 'read']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
