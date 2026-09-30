<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Portal\Dashboard;
use App\Livewire\Portal\Equipamentos;
use App\Livewire\Portal\Relatorios;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// 27.ª revisão de segurança: os componentes do portal protegem-se a si próprios (ApenasCliente).
// O isolamento por cliente só filtra o papel `cliente` — sem esta guarda, quem não é cliente e
// chegasse ao componente por /livewire/update (onde o middleware da rota não corre) via tudo.
class PortalGuardaTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(PapelUtilizador $papel, ?int $clienteId = null): User
    {
        return User::create(['nome' => 'U '.$papel->value, 'email' => $papel->value.'@x.pt', 'password' => 'x',
            'papel' => $papel, 'cliente_id' => $clienteId, 'ativo' => true]);
    }

    public function test_so_o_cliente_com_cliente_associado_usa_os_componentes_do_portal(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $doPortal = $this->utilizador(PapelUtilizador::Cliente, $cliente->id);

        foreach ([Dashboard::class, Equipamentos::class, Relatorios::class] as $componente) {
            Livewire::actingAs($doPortal)->test($componente)->assertOk();

            foreach ([PapelUtilizador::Admin, PapelUtilizador::Tecnico, PapelUtilizador::Financeiro] as $papel) {
                $outro = User::where('papel', $papel)->first() ?? $this->utilizador($papel);
                Livewire::actingAs($outro)->test($componente)->assertForbidden();
            }
        }

        // Cliente sem cliente associado: nada.
        $semCliente = User::create(['nome' => 'Sem', 'email' => 'sem@x.pt', 'password' => 'x', 'papel' => PapelUtilizador::Cliente, 'ativo' => true]);
        Livewire::actingAs($semCliente)->test(Relatorios::class)->assertForbidden();
    }
}
