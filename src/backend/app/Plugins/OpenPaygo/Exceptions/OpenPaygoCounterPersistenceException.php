<?php

namespace App\Plugins\OpenPaygo\Exceptions;

use App\Exceptions\MpmException;

class OpenPaygoCounterPersistenceException extends MpmException {
    protected int $httpStatusCode = 500;
}
