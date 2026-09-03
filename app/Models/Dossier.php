<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Dossiê espelhado do ERP PHC (tabela `bo`) — read-only na app, sincronizado por
// id_erp = bo.bostamp. Tipos: 1 (Encomenda Peças), 3 (Proposta), 7 (Encomenda Produção).
class Dossier extends Model
{
    protected $table = 'dossiers';

    // Rótulos dos tipos de dossiê (ndos) que sincronizamos do PHC.
    public const TIPOS = [
        1 => 'Encomenda Peças',
        3 => 'Proposta',
        7 => 'Encomenda Produção',
    ];

    /** @var list<string> */
    protected $fillable = [
        'id_erp',
        'ndos',
        'nmdos',
        'obrano',
        'data',
        'ano',
        'cliente_no',
        'nome',
        'total_debito',
        'fechada',
        'u_relat',
        'synced_at',
        'hash_sync',
        'ausente_do_erp_em',
        'alterado_erp_em',
        'alteracoes_erp',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'date',
            'total_debito' => 'decimal:2',
            'fechada' => 'boolean',
            'synced_at' => 'datetime',
            'ausente_do_erp_em' => 'datetime',
            'alterado_erp_em' => 'datetime',
            'alteracoes_erp' => 'array',
        ];
    }

    // Cliente correlacionado por cliente_no = clientes.id_erp (pode não existir na app).
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_no', 'id_erp');
    }

    // Campos que interessam comparar entre corridas (o resto — hashes, carimbos — é ruído
    // do próprio sync e não conta como "alteração no PHC").
    public const CAMPOS_ERP = ['ndos', 'nmdos', 'obrano', 'data', 'ano', 'cliente_no', 'nome', 'total_debito', 'fechada', 'u_relat'];

    // Rótulos legíveis dos campos, para dizer o que mudou sem falar em nomes de colunas.
    public const ROTULOS_ERP = [
        'ndos' => 'Tipo', 'nmdos' => 'Nome do tipo', 'obrano' => 'Nº', 'data' => 'Data',
        'ano' => 'Ano', 'cliente_no' => 'Nº de cliente', 'nome' => 'Cliente',
        'total_debito' => 'Total', 'fechada' => 'Estado', 'u_relat' => 'Relatório',
    ];

    // Já não existe no PHC (apagado lá, mantido cá).
    public function ausenteDoErp(): bool
    {
        return $this->ausente_do_erp_em !== null;
    }

    /**
     * O que mudou no PHC da última vez, em texto ('Total: 100,00 → 120,00').
     *
     * @return list<string>
     */
    public function alteracoesLegiveis(): array
    {
        $texto = [];
        foreach ($this->alteracoes_erp ?? [] as $campo => $mudanca) {
            $de = $mudanca['de'] ?? null;
            $para = $mudanca['para'] ?? null;
            $texto[] = (self::ROTULOS_ERP[$campo] ?? $campo).': '
                .($de === null || $de === '' ? '—' : $de).' → '.($para === null || $para === '' ? '—' : $para);
        }

        return $texto;
    }

    // Rótulo legível do tipo (fallback ao nome do PHC, depois ao número).
    public function tipoRotulo(): string
    {
        return self::TIPOS[$this->ndos] ?? ($this->nmdos ?: (string) $this->ndos);
    }
}
