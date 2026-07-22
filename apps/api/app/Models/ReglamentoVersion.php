<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReglamentoVersion extends Model
{
    protected $table = 'reglamento_versiones';

    protected $fillable = [
        'numero_version',
        'nombre',
        'fecha_vigencia',
        'documento_fuente',
    ];

    protected function casts(): array
    {
        return [
            'fecha_vigencia' => 'date',
        ];
    }

    public function tablasEvaluacion(): HasMany
    {
        return $this->hasMany(TablaEvaluacion::class);
    }
}
