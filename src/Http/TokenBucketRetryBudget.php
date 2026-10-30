<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

final class TokenBucketRetryBudget implements RetryBudget
{
    private float $tokens;
    private float $lastRefill;

    public function __construct(private readonly int $capacity, private readonly float $refillPerSecond)
    {
        if ($capacity < 1 || !is_finite($refillPerSecond) || $refillPerSecond < 0) {
            throw new \InvalidArgumentException('Retry budget capacity and refill rate must be non-negative.');
        }
        $this->tokens = (float) $capacity;
        $this->lastRefill = hrtime(true) / 1_000_000_000;
    }

    public function consume(): bool
    {
        $now = hrtime(true) / 1_000_000_000;
        $this->tokens = min($this->capacity, $this->tokens + max(0.0, $now - $this->lastRefill) * $this->refillPerSecond);
        $this->lastRefill = $now;
        if ($this->tokens < 1.0) return false;
        $this->tokens -= 1.0;
        return true;
    }
}