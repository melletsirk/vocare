<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CvSnapshot extends Model
{
    protected $table = 'cv_snapshots';

    protected $fillable = [
        'postulacion_id',
        'datos',
        'tomado_en',
    ];

    protected function casts(): array
    {
        return [
            'datos'    => 'array',
            'tomado_en' => 'datetime',
        ];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }
}
