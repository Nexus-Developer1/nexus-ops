<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Comercial avisado quando um serviço pode ser faturado (ver a migração): lista que se aprende
// com o uso, ligada ao código do vendedor do PHC (clientes.vendedor).
class Comercial extends Model
{
    protected $table = 'comerciais';

    /** @var list<string> */
    protected $fillable = ['email', 'vendedor_phc', 'ultimo_uso_em'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['vendedor_phc' => 'integer', 'ultimo_uso_em' => 'datetime'];
    }

    // Guarda (ou refresca) cada email usado e liga-o ao vendedor do cliente, quando o há.
    /** @param list<string> $emails */
    public static function aprender(array $emails, ?int $vendedorPhc): void
    {
        foreach ($emails as $email) {
            $email = mb_strtolower(trim($email));
            if ($email === '') {
                continue;
            }
            $comercial = static::firstOrNew(['email' => $email]);
            $comercial->ultimo_uso_em = now();
            if ($vendedorPhc !== null) {
                $comercial->vendedor_phc = $vendedorPhc;
            }
            $comercial->save();
        }
    }

    // Email do comercial já usado para este vendedor (o mais recente), para vir preenchido.
    public static function doVendedor(?int $vendedorPhc): ?string
    {
        if ($vendedorPhc === null) {
            return null;
        }

        return static::where('vendedor_phc', $vendedorPhc)->orderByDesc('ultimo_uso_em')->value('email');
    }
}
