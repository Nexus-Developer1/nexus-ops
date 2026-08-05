<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Artigo do catálogo do PHC (tabela st), sincronizado read-only por erp:sincronizar-artigos
// (upsert por id_erp = st.ref). Usado na pesquisa por referência ao compor os componentes
// de um sistema — nunca editado na aplicação.
class Artigo extends Model
{
    protected $fillable = [
        'id_erp',
        'designacao',
        'familia',
        'faminome',
        'hash_sync',
    ];
}
