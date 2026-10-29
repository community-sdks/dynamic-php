<?php

declare(strict_types=1);
namespace CommunitySDKs\Exception;

final class ValidationException extends SdkException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode('; ', $errors));
    }
}
