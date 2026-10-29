<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportJob extends Model
{
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'ico', 'status', 'draft'];

    protected function casts(): array
    {
        return [
            'draft' => 'array',
        ];
    }
}
