<?php

namespace App\Enums;

enum ProviderEventOutcome: string
{
    case APPLIED = 'APPLIED';
    case DUPLICATE_IGNORED = 'DUPLICATE_IGNORED';
    case REJECTED_INVALID_TRANSITION = 'REJECTED_INVALID_TRANSITION';
    case UNKNOWN_REQUEST = 'UNKNOWN_REQUEST';
}
