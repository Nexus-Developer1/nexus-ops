<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\User;
use App\Services\Agenda\FonteCalendario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Cores dos técnicos na agenda: guardadas na conta, atribuídas uma vez. Antes vinham da posição
// numa lista recalculada a cada pedido — mudavam sozinhas (bastava alguém passar a
// administrador) e repetiam-se assim que a equipa passava das 6 pessoas.
class AgendaCoresTecnicosTest extends TestCase
{
    use RefreshDatabase;

    private function pessoa(string $nome, PapelUtilizador $papel = PapelUtilizador::Tecnico): User
    {
        return User::create(['nome' => $nome, 'email' => str($nome)->slug().'@nexus.pt', 'password' => 'x',
            'papel' => $papel, 'ativo' => true]);
    }

    private function fonte(): FonteCalendario
    {
        return app(FonteCalendario::class);
    }

    public function test_cada_pessoa_da_equipa_tem_uma_cor_diferente(): void
    {
        $equipa = collect(['Ana', 'Bruno', 'Carla', 'Diogo', 'Eva', 'Filipe', 'Gil'])
            ->map(fn ($n) => $this->pessoa($n));
        $equipa->push($this->pessoa('Helena', PapelUtilizador::Admin)); // admins também vão a serviços

        $cores = $equipa->map(fn (User $u) => $this->fonte()->corTecnico($u->nome));

        $this->assertCount(8, $cores->unique(), 'Há cores repetidas: '.$cores->implode(', '));
        $this->assertTrue($cores->every(fn ($c) => in_array($c, FonteCalendario::PALETA, true)));
        $this->assertNotContains(FonteCalendario::COR_SEM_TECNICO, $cores->all());
    }

    public function test_a_cor_nao_muda_quando_a_equipa_muda(): void
    {
        $ana = $this->pessoa('Ana');
        $bruno = $this->pessoa('Bruno');
        $carla = $this->pessoa('Carla');

        $antes = [$this->fonte()->corTecnico('Ana'), $this->fonte()->corTecnico('Bruno'), $this->fonte()->corTecnico('Carla')];

        // O que antes baralhava tudo: a Ana passa a administradora, o Bruno é desativado e
        // entra gente nova. As cores de quem já lá estava têm de ficar exatamente iguais.
        $ana->update(['papel' => PapelUtilizador::Admin]);
        $bruno->update(['ativo' => false]);
        $this->pessoa('Duarte');
        $this->pessoa('Elsa');

        $depois = [$this->fonte()->corTecnico('Ana'), $this->fonte()->corTecnico('Bruno'), $this->fonte()->corTecnico('Carla')];

        $this->assertSame($antes, $depois);
        $this->assertSame($antes[2], $carla->fresh()->cor_agenda);
    }

    public function test_a_cor_fica_guardada_na_conta_e_e_reutilizada(): void
    {
        $ana = $this->pessoa('Ana');
        $this->assertNull($ana->cor_agenda);

        $cor = $ana->corAgenda();

        $this->assertSame($cor, $ana->fresh()->cor_agenda); // ficou gravada
        $this->assertSame($cor, $ana->fresh()->corAgenda()); // e não é recalculada
    }

    // Nome de evento antigo, só texto, sem conta: tem de ficar com uma cor que ninguém use.
    public function test_nome_legado_nao_apanha_a_cor_de_uma_pessoa_real(): void
    {
        $equipa = collect(['Ana', 'Bruno', 'Carla'])->map(fn ($n) => $this->pessoa($n));
        $reais = $equipa->map(fn (User $u) => $this->fonte()->corTecnico($u->nome));

        foreach (['Zeca Antigo', 'Manuel Saido', 'Joana Legada'] as $legado) {
            $this->assertNotContains($this->fonte()->corTecnico($legado), $reais->all());
        }

        // E é sempre a mesma para o mesmo nome.
        $this->assertSame($this->fonte()->corTecnico('Zeca Antigo'), app(FonteCalendario::class)->corTecnico('Zeca Antigo'));
    }

    public function test_sem_tecnico_usa_a_cor_neutra_e_a_legenda_traz_toda_a_gente(): void
    {
        $this->assertSame(FonteCalendario::COR_SEM_TECNICO, $this->fonte()->corTecnico(null));
        $this->assertSame(FonteCalendario::COR_SEM_TECNICO, $this->fonte()->corTecnico('  '));

        $this->pessoa('Ana');
        $this->pessoa('Bruno');

        $legenda = collect($this->fonte()->legenda());
        $this->assertSame(['Ana', 'Bruno'], $legenda->pluck('nome')->all());
        $this->assertCount(2, $legenda->pluck('cor')->unique());
    }
}
