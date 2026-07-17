<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

// Anexo polimórfico (fotos/documentos). Só metadados na BD; o ficheiro vive no object storage.
class Anexo extends Model
{
    protected $table = 'anexos';

    /** @var list<string> */
    protected $fillable = [
        'nome_ficheiro',
        'storage_key',
        'mime',
        'tamanho',
        'criado_por',
        'equipamento_id', // a que equipamento a foto pertence (null = geral / relatório antigo)
    ];

    // Equipamento a que a foto está associada (fotos por equipamento no relatório).
    public function equipamento(): BelongsTo
    {
        return $this->belongsTo(Equipamento::class);
    }

    public function conteudo(): string
    {
        return Storage::disk()->get($this->storage_key);
    }
}
