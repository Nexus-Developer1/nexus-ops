<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado do dossiê FACE AO PHC, apurado a cada sincronização.
 *
 * Até aqui o sync só sabia criar e atualizar: uma linha apagada no PHC ficava para sempre na
 * aplicação, sem ninguém dar por isso, e uma linha alterada era reescrita em silêncio. Estas
 * colunas guardam as duas respostas — "isto ainda existe no PHC?" e "o que mudou da última
 * vez?" — sem apagar nada (o espelho do ERP é read-only e pode ter ligações locais).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            // Quando reparámos que o dossiê deixou de existir no PHC (null = continua lá).
            $table->timestamp('ausente_do_erp_em')->nullable()->index()->after('synced_at');

            // Última vez que o conteúdo mudou DO LADO DO PHC, e o que mudou nessa altura
            // (campo => de/para). Diferente de synced_at, que muda em toda a escrita.
            $table->timestamp('alterado_erp_em')->nullable()->after('ausente_do_erp_em');
            $table->json('alteracoes_erp')->nullable()->after('alterado_erp_em');
        });
    }

    public function down(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            $table->dropColumn(['ausente_do_erp_em', 'alterado_erp_em', 'alteracoes_erp']);
        });
    }
};
