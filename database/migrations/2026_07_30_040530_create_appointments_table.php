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
        Schema::create('appointments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('client_id')->constrained('clients');
        $table->foreignId('stylist_id')->constrained('users'); // El empleado que atenderá
        $table->foreignId('service_id')->constrained('items'); // El servicio principal (Corte, Tinte, etc.)
        
        $table->dateTime('appointment_date'); // Fecha y hora de la cita
        $table->integer('duration_minutes')->default(30); // Para bloquear el espacio en la agenda
        
        $table->enum('status', ['pendiente', 'completada', 'cancelada', 'no_asistio'])->default('pendiente');
        $table->text('notes')->nullable(); // Requerimientos especiales del cliente
        
        $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
