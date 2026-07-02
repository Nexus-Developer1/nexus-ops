<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Utilizadores convidados nascem SEM password (definem-na ao aceitar o convite). Uma conta com
// password NULL é inutilizável até lá (o hasher rejeita hash nulo → login impossível).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE utilizadores ALTER COLUMN password DROP NOT NULL');
    }

    public function down(): void
    {
        // Reverte: exige password. (Assume que não há linhas com password NULL ao reverter.)
        DB::statement('ALTER TABLE utilizadores ALTER COLUMN password SET NOT NULL');
    }
};
