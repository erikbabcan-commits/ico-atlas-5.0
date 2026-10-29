<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_log';

    protected $fillable = [
        'ico', 'caller', 'http_status', 'endpoint', 'retrieved_at', 'raw_hash', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'retrieved_at' => 'datetime',
        ];
    }
}
