<?php

namespace Tests\Feature;

use App\Enums\EstadoRelatorio;
use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Autosave de campo do editor de relatórios: grava o rascunho em silêncio (sem erros
// visíveis, sem navegação) para um refresh/pull-to-refresh no iPad não levar o trabalho.
// NUNCA toca em relatórios finalizados/enviados — despromover a rascunho é decisão do
// guardar manual, com aviso.
class RelatorioAutosaveTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(): array
    {
        $tecnico = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'AUTO-1']);

        return [$tecnico, $equip];
    }

    public function test_autosave_cria_o_rascunho_sem_navegar(): void
    {
        [$tecnico, $equip] = $this->cenario();

        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('resumo', 'Trabalho a meio, escrito em campo')
            ->call('autoGravar')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            // O editor troca o URL por history.replaceState com o URL enviado no evento.
            ->assertDispatched('auto-gravado');

        $relatorio = Relatorio::firstOrFail();
        $this->assertSame(EstadoRelatorio::Rascunho, $relatorio->estado);
        $this->assertSame('Trabalho a meio, escrito em campo', $relatorio->intervencao->trabalho_realizado);
    }

    public function test_autosave_nao_toca_em_relatorio_finalizado(): void
    {
        [$tecnico, $equip] = $this->cenario();

        // Cria o rascunho pelo caminho normal e finaliza-o na BD (documento oficial).
        $comp = Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->call('autoGravar');
        $relatorio = Relatorio::firstOrFail();
        $relatorio->update(['estado' => EstadoRelatorio::Finalizado]);

        // O autosave seguinte (mesmo componente, relatorioId já definido) é um no-op:
        // o estado NÃO volta a rascunho e o evento de autosave não dispara.
        $comp->set('resumo', 'alteração tardia')
            ->call('autoGravar')
            ->assertNotDispatched('auto-gravado');

        $this->assertSame(EstadoRelatorio::Finalizado, $relatorio->fresh()->estado);
    }

    public function test_autosave_com_validacao_a_falhar_fica_em_silencio(): void
    {
        [$tecnico] = $this->cenario();

        // Sem equipamento não há nada para gravar; e mesmo meio-preenchido com validação a
        // falhar, o autosave não pode espalhar erros pelo ecrã a meio da escrita.
        Livewire::actingAs($tecnico)->test(Novo::class)
            ->call('autoGravar')
            ->assertHasNoErrors()
            ->assertNotDispatched('auto-gravado');

        $this->assertSame(0, Relatorio::count());
    }
}
