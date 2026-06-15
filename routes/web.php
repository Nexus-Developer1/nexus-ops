<?php

use App\Livewire\Auth\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

// Autenticação
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
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
    $disco = \Illuminate\Support\Facades\Storage::disk('s3');

    if (! $relatorio->pdf_path || ! $disco->exists($relatorio->pdf_path)) {
        $gerador->gerarPdf($relatorio);
        $relatorio->refresh();
    }

    return response($disco->get($relatorio->pdf_path))
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'inline; filename="' . str_replace('/', '-', $relatorio->numero) . '.pdf"');
};

// ---- Gestão (só admin) — dashboard de gestão, contratos, alertas, ativos ----
Route::middleware(['auth', 'papel:admin'])->group(function () {
    Route::get('/dashboard', \App\Livewire\DashboardGestao::class)->name('dashboard');

    Route::get('/ativos', \App\Livewire\Equipamentos\Listagem::class)->name('ativos');
    Route::get('/ativos/novo', \App\Livewire\Equipamentos\Editor::class)->name('equipamentos.novo');
    Route::get('/ativos/{equipamento}/editar', \App\Livewire\Equipamentos\Editor::class)->name('equipamentos.editar');

    // Contratos (rota /novo declarada ANTES de /{contrato} para não colidir).
    Route::get('/contratos', \App\Livewire\Contratos\Listagem::class)->name('contratos');
    Route::get('/contratos/novo', \App\Livewire\Contratos\Editor::class)->name('contratos.novo');
    Route::get('/contratos/{contrato}/editar', \App\Livewire\Contratos\Editor::class)->name('contratos.editar');
    Route::get('/contratos/{contrato}', \App\Livewire\Contratos\Ficha::class)->name('contratos.ficha');

    // Alertas proativos (renovações, baterias, visitas em atraso, SLA).
    Route::get('/alertas', \App\Livewire\Alertas\Painel::class)->name('alertas');
});

// ---- Painel inicial do técnico (a sua operação) ----
Route::middleware(['auth', 'papel:tecnico'])->group(function () {
    Route::get('/painel', \App\Livewire\Painel\Tecnico::class)->name('painel');
});

// ---- Operação de campo (admin + técnico) — agenda, intervenções, relatórios ----
// Para técnicos, os dados são filtrados aos seus (global scopes RestritoAoTecnico).
Route::middleware(['auth', 'papel:admin,tecnico'])->group(function () use ($servirPdf) {
    // Ficha de equipamento (leitura em campo — ex.: QR code).
    Route::get('/ativos/{equipamento}', \App\Livewire\Equipamentos\Ficha::class)->name('equipamentos.ficha');

    Route::get('/intervencoes/{intervencao}', \App\Livewire\Intervencoes\Formulario::class)->name('intervencoes.formulario');

    // Agenda (calendário de visitas, intervenções e ausências).
    Route::get('/agenda', \App\Livewire\Agenda\Calendario::class)->name('agenda');

    Route::get('/relatorios', \App\Livewire\Relatorios\Listagem::class)->name('relatorios');
    // /novo ANTES de qualquer rota com parâmetro para não colidir.
    Route::get('/relatorios/novo', \App\Livewire\Relatorios\Novo::class)->name('relatorios.novo');
    Route::get('/relatorios/{relatorio}/pdf', $servirPdf)->name('relatorios.pdf');

    // Proxy aos anexos no object storage (evita expor o MinIO ao browser).
    Route::get('/anexos/{anexo}', function (\App\Models\Anexo $anexo) {
        $disco = \Illuminate\Support\Facades\Storage::disk('s3');
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
        $disco = \Illuminate\Support\Facades\Storage::disk('s3');

        if (! $relatorio->pdf_path || ! $disco->exists($relatorio->pdf_path)) {
            $gerador->gerarPdf($relatorio);
            $relatorio->refresh();
        }

        return response($disco->get($relatorio->pdf_path))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . str_replace('/', '-', $relatorio->numero) . '.pdf"');
    })->name('relatorios.pdf');
});
