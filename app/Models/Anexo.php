<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
    ];

    public function anexavel(): MorphTo
    {
        return $this->morphTo();
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    // URL pública servida pela aplicação (proxy ao object storage).
    public function url(): string
    {
        return route('anexos.ver', $this);
    }

    public function conteudo(): string
    {
        return Storage::disk()->get($this->storage_key);
    }
}
