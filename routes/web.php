<?php

use App\Livewire\Auth\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

// Autenticação
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');

    // Recuperação de palavra-passe (broker nativo do Laravel).
    Route::get('/esqueci-password', \App\Livewire\Auth\EsqueciPassword::class)->name('password.request');
    Route::get('/redefinir-password/{token}', \App\Livewire\Auth\RedefinirPassword::class)->name('password.reset');

    // Aceitar convite: definir a password de uma conta nova (broker 'invites', uso único).
    Route::get('/convite/{token}', \App\Livewire\Auth\AceitarConvite::class)->name('convite.definir');
});

// Feed iCal de um técnico — URL assinada, acessível por apps de calendário
// externas (sem sessão). CLAUDE.md §6.
Route::get('/agenda/ical/{tecnico}.ics', function (\App\Models\User $tecnico, \App\Services\Agenda\GeradorIcal $gerador) {
    return response($gerador->paraTecnico($tecnico))
        ->header('Content-Type', 'text/calendar; charset=utf-8')
        ->header('Content-Disposition', 'inline; filename="agenda-' . $tecnico->id . '.ics"');
})->middleware('signed')->name('agenda.ical');

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

// Função partilhada: serve o PDF de um relatório (gera-o on-demand se necessário).
$servirPdf = function (\App\Models\Relatorio $relatorio, \App\Services\GeradorRelatorio $gerador) {
    $disco = \Illuminate\Support\Facades\Storage::disk();

    if (! $relatorio->pdf_path || ! $disco->exists($relatorio->pdf_path)) {
        $gerador->gerarPdf($relatorio);
        $relatorio->refresh();
    }

    return response($disco->get($relatorio->pdf_path))
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="' . str_replace('/', '-', $relatorio->numero) . '.pdf"');
};

// ---- Gestão da operação (admin + técnico) — dashboard, contratos, alertas, ativos, despesas.
// O técnico tem as mesmas permissões que o admin, EXCETO gerir utilizadores (grupo abaixo). ----
Route::middleware(['auth', 'papel:admin,tecnico'])->group(function () {
    Route::get('/dashboard', \App\Livewire\DashboardGestao::class)->name('dashboard');

    Route::get('/ativos', \App\Livewire\Equipamentos\Listagem::class)->name('ativos');
    // Associar um equipamento existente a um local (rota literal ANTES de /ativos/{equipamento}).
    Route::get('/ativos/associar/{equipamento?}', \App\Livewire\Equipamentos\Associar::class)->name('equipamentos.associar');

    // Contratos (rota /novo declarada ANTES de /{contrato} para não colidir).
    Route::get('/contratos', \App\Livewire\Contratos\Listagem::class)->name('contratos');
    Route::get('/contratos/novo', \App\Livewire\Contratos\Editor::class)->name('contratos.novo');
    Route::get('/contratos/{contrato}/editar', \App\Livewire\Contratos\Editor::class)->name('contratos.editar');
    Route::get('/contratos/{contrato}', \App\Livewire\Contratos\Ficha::class)->name('contratos.ficha');

    // Alertas proativos (renovações, baterias, visitas em atraso, SLA).
    Route::get('/alertas', \App\Livewire\Alertas\Painel::class)->name('alertas');

    // Despesas (custos da operação). Rota /nova ANTES de /{despesa} para não colidir.
    Route::get('/despesas', \App\Livewire\Despesas\Listagem::class)->name('despesas');
    Route::get('/despesas/nova', \App\Livewire\Despesas\Editor::class)->name('despesas.nova');
    Route::get('/despesas/{despesa}/editar', \App\Livewire\Despesas\Editor::class)->name('despesas.editar');
});

// ---- Gestão de utilizadores (ÚNICA área exclusiva do admin) ----
// Middleware admin,tecnico para o técnico CHEGAR ao componente e levar um 403 real do Gate
// 'gerir-utilizadores' (o middleware papel:admin redirecionaria, não daria 403). A guarda
// verdadeira é o abort_unless(Gate) no componente.
Route::middleware(['auth', 'papel:admin,tecnico'])->group(function () {
    Route::get('/utilizadores/adicionar', \App\Livewire\Utilizadores\Adicionar::class)->name('utilizadores.adicionar');
});

// ---- Operação de campo (admin + técnico) — agenda, intervenções, relatórios ----
// Para técnicos, os dados são filtrados aos seus (global scopes RestritoAoTecnico).
Route::middleware(['auth', 'papel:admin,tecnico'])->group(function () use ($servirPdf) {
    // Consulta de clientes (só leitura — origem ERP).
    Route::get('/clientes', \App\Livewire\Clientes\Index::class)->name('clientes');
    Route::get('/clientes/{cliente}', \App\Livewire\Clientes\Detalhe::class)->name('clientes.detalhe');
    Route::get('/clientes/{cliente}/equipamentos', \App\Livewire\Clientes\Equipamentos::class)->name('clientes.equipamentos');
    Route::get('/clientes/{cliente}/contratos', \App\Livewire\Clientes\Contratos::class)->name('clientes.contratos');
    Route::get('/clientes/{cliente}/relatorios', \App\Livewire\Clientes\Relatorios::class)->name('clientes.relatorios');
    Route::get('/clientes/{cliente}/faturacao', \App\Livewire\Clientes\Faturacao::class)->name('clientes.faturacao');
    Route::get('/clientes/{cliente}/faturacao/{linha}', \App\Livewire\Clientes\Fatura::class)->name('clientes.fatura');

    // Ficha de equipamento (leitura em campo — ex.: QR code).
    Route::get('/ativos/{equipamento}', \App\Livewire\Equipamentos\Ficha::class)->name('equipamentos.ficha');

    // "Abrir intervenção": o trabalho preenche-se no EDITOR DE RELATÓRIO (fonte única — abas
    // de equipamento, ficha de medições, etc.). Garante um rascunho ligado e redireciona.
    Route::get('/intervencoes/{intervencao}', function (\App\Models\Intervencao $intervencao) {
        // withTrashed: distingue relatório VIVO / ELIMINADO / inexistente numa só query. O
        // "nulls first" faz um relatório vivo ganhar a um eliminado (dados já corrompidos pela
        // bug antiga não bloqueiam indevidamente).
        $relatorio = $intervencao->relatorio()->withTrashed()
            ->orderByRaw('deleted_at asc nulls first')
            ->first();

        // Relatório eliminado → NÃO ressuscita nem cria fantasma. Explica e volta à listagem.
        if ($relatorio && $relatorio->trashed()) {
            return redirect()->route('relatorios')
                ->with('erro', 'O relatório desta intervenção foi eliminado.');
        }

        // Vivo → abre esse; nunca existiu → cria o rascunho (ponto único, à prova de corrida).
        $relatorio ??= $intervencao->garantirRascunho();

        return redirect()->route('relatorios.editar', $relatorio);
    })->name('intervencoes.formulario');

    // Agenda (calendário de visitas, intervenções e ausências).
    Route::get('/agenda', \App\Livewire\Agenda\Calendario::class)->name('agenda');

    Route::get('/relatorios', \App\Livewire\Relatorios\Listagem::class)->name('relatorios');
    // /novo ANTES de qualquer rota com parâmetro para não colidir.
    Route::get('/relatorios/novo', \App\Livewire\Relatorios\Novo::class)->name('relatorios.novo');
    Route::get('/relatorios/{relatorio}/editar', \App\Livewire\Relatorios\Novo::class)->name('relatorios.editar');
    Route::get('/relatorios/{relatorio}/enviar', \App\Livewire\Relatorios\Enviar::class)->name('relatorios.enviar');
    Route::get('/relatorios/{relatorio}/pdf', $servirPdf)->name('relatorios.pdf');

    // Proxy aos anexos no object storage (evita expor o MinIO ao browser).
    Route::get('/anexos/{anexo}', function (\App\Models\Anexo $anexo) {
        $disco = \Illuminate\Support\Facades\Storage::disk();
        abort_unless($disco->exists($anexo->storage_key), 404);

        return response($disco->get($anexo->storage_key))
            ->header('Content-Type', $anexo->mime ?? 'application/octet-stream');
    })->name('anexos.ver');
});

// Portal do cliente (só leitura). O isolamento por cliente é imposto na camada de
// dados (global scope RestritoAoCliente) — aqui é defesa em profundidade (§7).
Route::middleware(['auth', 'papel:cliente'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', \App\Livewire\Portal\Dashboard::class)->name('dashboard');
    Route::get('/equipamentos', \App\Livewire\Portal\Equipamentos::class)->name('equipamentos');
    Route::get('/relatorios', \App\Livewire\Portal\Relatorios::class)->name('relatorios');

    // PDF do relatório — o route-model-binding aplica o global scope: um cliente
    // só resolve relatórios seus (os de outro cliente dão 404).
    Route::get('/relatorios/{relatorio}/pdf', function (\App\Models\Relatorio $relatorio, \App\Services\GeradorRelatorio $gerador) {
        $disco = \Illuminate\Support\Facades\Storage::disk('local');

        if (! $relatorio->pdf_path || ! $disco->exists($relatorio->pdf_path)) {
            $gerador->gerarPdf($relatorio);
            $relatorio->refresh();
        }

        return response($disco->get($relatorio->pdf_path))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . str_replace('/', '-', $relatorio->numero) . '.pdf"');
    })->name('relatorios.pdf');
});
