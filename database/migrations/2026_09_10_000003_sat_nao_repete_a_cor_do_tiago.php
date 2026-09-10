<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A conta SAT tinha ficado com um turquesa (#005e5e) que já não é de nenhuma categoria do
// Outlook e que caía na MESMA categoria do Tiago Pinto (turquesa escuro) — dois nomes com a
// mesma cor na grelha. Passa ao verde, que ninguém usa.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('utilizadores')->where('email', 'sat@nxs.pt')->where('cor_agenda', '#005e5e')
            ->update(['cor_agenda' => '#22b14c']); // verde (preset4)
    }

    public function down(): void
    {
        DB::table('utilizadores')->where('email', 'sat@nxs.pt')->where('cor_agenda', '#22b14c')
            ->update(['cor_agenda' => '#005e5e']);
    }
};
