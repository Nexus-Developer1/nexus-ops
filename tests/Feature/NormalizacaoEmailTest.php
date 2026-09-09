<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Emails são case-insensitive: o mutator do modelo guarda-os sempre em minúsculas e sem
// espaços, venham de onde vierem (sync do ERP, portal, seeds). Os ecrãs de autenticação
// que normalizavam o input passaram para o portal em set. 2026.
class NormalizacaoEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_mutator_grava_email_em_minusculas(): void
    {
        $u = User::create(['nome' => 'X', 'email' => '  Suporte@NXS.pt ', 'password' => 'segredo123', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        $this->assertSame('suporte@nxs.pt', $u->fresh()->email); // minúsculas + trim
    }

    public function test_mutator_tambem_normaliza_em_alteracoes(): void
    {
        $u = User::create(['nome' => 'X', 'email' => 'antigo@nxs.pt', 'password' => 'segredo123', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        $u->update(['email' => ' Novo.Email@NXS.PT ']);

        $this->assertSame('novo.email@nxs.pt', $u->fresh()->email);
    }

    public function test_procura_por_email_encontra_a_conta_seja_qual_for_a_capitalizacao(): void
    {
        User::create(['nome' => 'Admin', 'email' => 'suporte@nxs.pt', 'password' => 'segredo123', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        // É assim que a aplicação procura contas (sync, comandos): sempre em minúsculas.
        $encontrada = User::whereRaw('lower(email) = ?', [strtolower(trim(' Suporte@NXS.pt '))])->first();

        $this->assertNotNull($encontrada);
        $this->assertSame('suporte@nxs.pt', $encontrada->email);
    }
}
