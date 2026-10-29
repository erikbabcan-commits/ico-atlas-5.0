<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $primaryKey = 'ico';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'ico', 'name', 'name_norm', 'status', 'legal_form', 'legal_form_code',
        'seat_norm', 'street', 'municipality', 'postal_code', 'country',
        'dic', 'ic_dph', 'established_on', 'terminated_on',
        'raw', 'sources', 'retrieved_at',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'sources' => 'array',
            'retrieved_at' => 'datetime',
            'established_on' => 'date',
            'terminated_on' => 'date',
        ];
    }
}
