<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Livewire\Auth\EsqueciPassword;
use App\Livewire\Contratos\Editor;
use App\Livewire\Equipamentos\Associar;
use App\Livewire\Equipamentos\Novo as EquipamentoNovo;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Endurecimentos de segurança (revisão 2026-07-16): o trait ApenasEquipa barra o papel cliente
// em TODAS as requisições ao componente (não só via middleware da rota), e o "esqueci password"
// não revela se um email existe.
class SegurancaHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function tecnico(): User
    {
        return User::create(['nome' => 'Téc', 'email' => 'tec@x.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    private function cliente(): User
    {
        $c = Cliente::create(['nome' => 'ACME', 'ativo' => true]);

        return User::create(['nome' => 'Portal', 'email' => 'cli@x.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Cliente, 'cliente_id' => $c->id, 'ativo' => true]);
    }

    // Invoca cada componente DIRETAMENTE (Livewire::test contorna o middleware da rota), por isso o
    // 403 prova que o guard vem do trait ApenasEquipa, não do middleware papel:admin,tecnico. O
    // Livewire captura o abort do mount como RESPOSTA (daí assertForbidden, não uma exceção lançada).
    public function test_trait_apenas_equipa_bloqueia_cliente_em_todos_os_componentes(): void
    {
        $cliente = $this->cliente();

        foreach ([Calendario::class, EquipamentoNovo::class, Associar::class, Editor::class] as $componente) {
            Livewire::actingAs($cliente);
            Livewire::test($componente)->assertForbidden();
        }
    }

    public function test_trait_apenas_equipa_deixa_passar_o_tecnico(): void
    {
        $this->actingAs($this->tecnico());

        // O técnico renderiza normalmente — o guard só barra clientes.
        Livewire::test(EquipamentoNovo::class)->assertOk();
        Livewire::test(Calendario::class)->assertOk();
    }

    public function test_esqueci_password_mostra_mensagem_neutra_para_email_inexistente(): void
    {
        Livewire::test(EsqueciPassword::class)
            ->set('email', 'ninguem@inexistente.pt')
            ->call('enviarLink')
            ->assertHasNoErrors()
            ->assertSet('estado', 'Se existir uma conta com esse email, enviámos um link para redefinir a palavra-passe.');
    }
}
