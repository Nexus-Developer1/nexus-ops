<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A folha MENSAL de despesas foi removida no próprio dia (decisão da equipa: o registo é por
// despesa INDIVIDUAL, com recibos digitalizados). Esta migração desfaz a anterior com guardas
// (corre bem quer a criação tenha sido aplicada quer não). As despesas lançadas pela grelha
// sobrevivem como despesas individuais — só perdem a ligação à folha.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('despesas', 'folha_despesa_id')) {
            Schema::table('despesas', function (Blueprint $table) {
                $table->dropConstrainedForeignId('folha_despesa_id');
            });
        }

        // Recibos que tenham sido anexados a folhas (metadados; os ficheiros ficam órfãos
        // no storage — sem risco, e recuperáveis à mão se alguma vez fizer falta).
        DB::table('anexos')->where('anexavel_type', 'like', '%FolhaDespesa')->delete();

        Schema::dropIfExists('folhas_despesa');
    }

    public function down(): void
    {
        Schema::create('folhas_despesa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('utilizadores');
            $table->unsignedSmallInteger('ano');
            $table->unsignedTinyInteger('mes');
            $table->string('matricula')->nullable();
            $table->string('departamento')->nullable();
            $table->decimal('adiantado', 10, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['user_id', 'ano', 'mes']);
        });

        Schema::table('despesas', function (Blueprint $table) {
            $table->foreignId('folha_despesa_id')->nullable()->constrained('folhas_despesa')->nullOnDelete();
        });
    }
};
