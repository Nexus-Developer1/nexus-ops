<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Rascunhos de relatório não têm número — o número sequencial só é atribuído ao
// finalizar. Torna relatorios.numero nullable (o índice unique permite vários NULL).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE relatorios ALTER COLUMN numero DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE relatorios ALTER COLUMN numero SET NOT NULL');
    }
};
