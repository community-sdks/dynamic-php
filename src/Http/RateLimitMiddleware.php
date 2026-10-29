<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

final class RateLimitMiddleware implements HttpMiddleware
{
    private float $nextRequestAt = 0.0;

    public function __construct(private readonly float $minimumIntervalSeconds)
    {
        if (!is_finite($minimumIntervalSeconds) || $minimumIntervalSeconds < 0) {
            throw new \InvalidArgumentException('Rate limit interval must be a finite non-negative number.');
        }
    }

    public function process(Request $request, callable $next): Response
    {
        $now = hrtime(true) / 1_000_000_000;
        if ($this->nextRequestAt > $now) {
            usleep((int) ceil(($this->nextRequestAt - $now) * 1_000_000));
            $now = hrtime(true) / 1_000_000_000;
        }
        $this->nextRequestAt = $now + $this->minimumIntervalSeconds;
        return $next($request);
    }
}