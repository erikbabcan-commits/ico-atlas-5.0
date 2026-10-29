<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Edge extends Model
{
    protected $fillable = [
        'from_type', 'from_id', 'to_type', 'to_id', 'edge_type',
        'valid_from', 'valid_to', 'source', 'source_url',
        'retrieved_at', 'raw_hash', 'confidence', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'retrieved_at' => 'datetime',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'confidence' => 'float',
        ];
    }

    public const TYPE_PERSON = 'person';

    public const TYPE_COMPANY = 'company';

    public const TYPE_ADDRESS = 'address';

    public const TYPE_EVENT = 'event';

    public const EDGE_STATUTORY = 'STATUTORY';

    public const EDGE_SHAREHOLDER = 'SHAREHOLDER';

    public const EDGE_UBO = 'UBO';

    public const EDGE_SAME_SEAT = 'SAME_SEAT';

    public const EDGE_INSOLVENCY = 'INSOLVENCY';

    public const EDGE_PUBLIC_MONEY = 'PUBLIC_MONEY';

    public const EDGE_PREDECESSOR = 'PREDECESSOR';

    public const EDGE_SUCCESSOR = 'SUCCESSOR';
}
