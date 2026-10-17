<?php

namespace Talario\Laravel;

use Symfony\Component\Mailer\Exception\TransportException;

class TalarioTransportException extends TransportException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $apiCode = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
