<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class SubscriptionLimitExceededException extends HttpException
{
    public function __construct(
        public readonly string $resourceType,
        public readonly int $currentCount,
        public readonly ?int $limit
    ) {
        $message = "Your subscription plan allows a maximum of {$limit} {$resourceType}(s). You currently have {$currentCount}.";

        parent::__construct(403, $message);
    }
}
