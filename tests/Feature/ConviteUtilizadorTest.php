<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

// Convites e gestão de utilizadores vivem no PORTAL da suite (set. 2026): os ecrãs locais
// foram apagados. Fica o que continua a ser desta aplicação — o endereço antigo encaminha
// para o portal, e uma conta convidada (sem palavra-passe) nunca autentica aqui.
class ConviteUtilizadorTest extends TestCase
{
    use RefreshDatabase;

    private function tecnico(): User
    {
        return User::create(['nome' => 'Téc', 'email' => 'tec@nexus.pt', 'password' => 'segredo123', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    public function test_url_de_utilizadores_redireciona_para_o_portal(): void
    {
        $destino = $this->actingAs($this->tecnico())
            ->get(route('utilizadores.adicionar'))
            ->headers->get('Location');

        $this->assertStringStartsWith(rtrim(config('app.portal_url'), '/'), (string) $destino);
    }

    public function test_utilizador_sem_password_nao_faz_login(): void
    {
        $semPassword = User::create(['nome' => 'Sem Pass', 'email' => 'sempass@nexus.pt', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $this->assertNull($semPassword->password);

        // Qualquer tentativa de login falha (o hasher rejeita hash nulo).
        $this->assertFalse(Auth::attempt(['email' => 'sempass@nexus.pt', 'password' => 'qualquer']));
        $this->assertFalse(Auth::attempt(['email' => 'sempass@nexus.pt', 'password' => '']));
        $this->assertGuest();
    }
}
