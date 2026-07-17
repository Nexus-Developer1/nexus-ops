<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Um evento de agenda pode ter VÁRIOS técnicos (espelha intervencao_tecnicos dos relatórios).
// eventos_agenda.tecnico_id continua a ser o principal (cor, compatibilidade); os restantes
// vivem nesta pivot. Todos contam para conflitos/ausências, iCal e notificações.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evento_tecnicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_agenda_id')->constrained('eventos_agenda')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('utilizadores')->cascadeOnDelete();
            $table->unique(['evento_agenda_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_tecnicos');
    }
};
