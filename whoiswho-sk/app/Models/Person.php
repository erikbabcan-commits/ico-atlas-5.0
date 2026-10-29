<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Person extends Model
{
    protected $table = 'persons';

    protected $fillable = [
        'name_norm', 'full_name', 'given_name', 'family_name', 'born_on', 'raw',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'born_on' => 'date',
        ];
    }
}
