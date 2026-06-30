<?php

namespace Tests\Feature;

use App\Enums\EstadoContrato;
use App\Enums\PapelUtilizador;
use App\Livewire\Contratos\Ficha;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Ativação do contrato: muda só o estado para Ativo, exige ≥1 equipamento, sem gerar
// visitas (modelo automático foi removido — as visitas são agendadas à mão na agenda).
class ContratoAtivacaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function contratoCom(int $nEquip, $inicio, $fim, string $numero): Contrato
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'acme@x.pt', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $ids = [];
        for ($i = 0; $i < $nEquip; $i++) {
            $ids[] = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'X' . $i])->id;
        }
        $contrato = Contrato::create([
            'numero' => $numero, 'cliente_id' => $cliente->id,
            'data_inicio' => $inicio, 'data_fim' => $fim,
            'estado' => EstadoContrato::Rascunho, 'tipo' => 'preventiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);
        $contrato->equipamentos()->sync($ids);

        return $contrato;
    }

    public function test_ativar_nao_gera_visitas(): void
    {
        // Ativar passa o contrato a Ativo SEM gerar visitas na agenda.
        $contrato = $this->contratoCom(2, now()->startOfYear(), now()->startOfYear()->addMonths(6), '2026/8002');

        Livewire::actingAs($this->admin())->test(Ficha::class, ['contrato' => $contrato])
            ->call('ativar');

        $contrato->refresh();
        $this->assertSame(EstadoContrato::Ativo, $contrato->estado);
        $this->assertSame(0, $contrato->eventos()->count()); // nenhuma visita gerada
    }

    public function test_ativar_sem_equipamento_e_bloqueado(): void
    {
        // Sem equipamentos → continua a não poder ser ativado (requisito mantido).
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'q@x.pt', 'ativo' => true]);
        $contrato = Contrato::create([
            'numero' => '2026/8007', 'cliente_id' => $cliente->id,
            'data_inicio' => now(), 'data_fim' => now()->addYear(),
            'estado' => EstadoContrato::Rascunho, 'tipo' => 'preventiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);

        Livewire::actingAs($this->admin())->test(Ficha::class, ['contrato' => $contrato])
            ->call('ativar');

        $this->assertSame(EstadoContrato::Rascunho, $contrato->fresh()->estado); // continua rascunho
    }

    public function test_ativar_nao_mexe_em_eventos_existentes(): void
    {
        // Um evento já na agenda fica intacto quando se ativa um contrato.
        $contrato = $this->contratoCom(1, now()->startOfYear(), now()->endOfYear(), '2026/8008');
        $evento = EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => 'V', 'estado' => 'planeado',
            'inicio' => now(), 'fim' => now()->addHour(), 'cliente_id' => $contrato->cliente_id]);

        Livewire::actingAs($this->admin())->test(Ficha::class, ['contrato' => $contrato])->call('ativar');

        $this->assertDatabaseHas('eventos_agenda', ['id' => $evento->id, 'estado' => 'planeado']);
        $this->assertDatabaseCount('eventos_agenda', 1); // não criou nem apagou nada
    }
}
