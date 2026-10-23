<?php

namespace App\Enums;

enum AttemptOutcome: string
{
    case SUCCESS = 'SUCCESS';
    case TEMPORARY_FAILURE = 'TEMPORARY_FAILURE';
    case TIMEOUT = 'TIMEOUT';
    case PENDING = 'PENDING';
    case ERROR = 'ERROR';
}
