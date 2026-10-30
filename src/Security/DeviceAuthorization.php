<?php

declare(strict_types=1);
namespace CommunitySDKs\Security;

final readonly class DeviceAuthorization
{
    public function __construct(
        public string $deviceCode,
        public string $userCode,
        public string $verificationUri,
        public ?string $verificationUriComplete,
        public int $expiresIn,
        public int $interval,
    ) {}
}