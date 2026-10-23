<?php

namespace App\Exceptions;

use Exception;

class InvalidStateTransitionException extends Exception
{
    public function __construct(string $fromState, string $toState, string $reason = '')
    {
        $message = "Cannot transition policy request from state [{$fromState}] to [{$toState}].";
        if (!empty($reason)) {
            $message .= " Context: {$reason}";
        }
        parent::__construct($message, 409);
    }
}
