<?php

namespace App\Models;

use App\Enums\EstadoRelatorio;
use App\Models\Concerns\RestritoAoCliente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Relatório gerado de uma intervenção (com numeração sequencial).
class Relatorio extends Model
{
    use RestritoAoCliente, SoftDeletes;

    protected $table = 'relatorios';

    // Isolamento por cliente (via intervenção → equipamento → local). NÃO há isolamento por
    // técnico: o técnico tem a mesma visibilidade que o admin (exceto gerir utilizadores).
    protected static function restringirAoCliente(Builder $query, int $clienteId): void
    {
        $query->whereHas('intervencao.equipamento.local', fn ($q) => $q->where('cliente_id', $clienteId));
    }

    /** @var list<string> */
    protected $fillable = [
        'intervencao_id',
        'numero',
        'data',
        'estado',
        'pdf_path',
        'pdf_enviado_path',
        'pdf_enviado_sha256',
        'enviado_versao',
        'enviado_em',
        'enviado_para',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'date',
            'estado' => EstadoRelatorio::class,
            'enviado_em' => 'datetime',
        ];
    }

    // Foi entregue ao cliente pelo menos uma vez? Um enviado continua editável (decisão da
    // equipa, jul. 2026) e ao gravar volta a finalizado/rascunho — mas o cliente já tem uma
    // versão, por isso NUNCA se elimina: conta a data do envio e a cópia congelada, não o
    // estado atual (25.ª revisão de segurança, set. 2026).
    public function jaFoiEnviado(): bool
    {
        return $this->estado === EstadoRelatorio::Enviado
            || $this->enviado_em !== null
            || filled($this->pdf_enviado_path);
    }

    public function intervencao(): BelongsTo
    {
        return $this->belongsTo(Intervencao::class);
    }
}
