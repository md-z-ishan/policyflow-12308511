<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MockPolicy extends Model
{
    protected $fillable = [
        'client_reference',
        'provider_reference',
        'policy_number',
        'scenario',
        'status',
    ];
}
