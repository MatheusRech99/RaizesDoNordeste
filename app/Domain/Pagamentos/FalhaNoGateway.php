<?php

namespace App\Domain\Pagamentos;

use Exception;

// timeout, erro de comunicacao ou gateway fora do ar
class FalhaNoGateway extends Exception
{
}
