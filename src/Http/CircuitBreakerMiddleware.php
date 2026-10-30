<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

use CommunitySDKs\Exception\TransportException;
use Throwable;

final class CircuitBreakerMiddleware implements HttpMiddleware
{
    private int $failures = 0;
    private ?float $openedAt = null;
    private bool $probeInProgress = false;

    /** @param list<int> $failureStatuses */
    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly float $resetAfterSeconds = 30.0,
        private readonly array $failureStatuses = [500, 502, 503, 504],
    ) {
        if ($failureThreshold < 1 || !is_finite($resetAfterSeconds) || $resetAfterSeconds <= 0) {
            throw new \InvalidArgumentException('Circuit breaker threshold and reset time must be positive.');
        }
    }

    public function process(Request $request, callable $next): Response
    {
        $halfOpen = false;
        if ($this->openedAt !== null) {
            if (hrtime(true) / 1_000_000_000 - $this->openedAt < $this->resetAfterSeconds || $this->probeInProgress) {
                throw new TransportException('Circuit breaker is open.');
            }
            $halfOpen = true;
            $this->probeInProgress = true;
        }
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->recordFailure($halfOpen);
            throw $e;
        }
        if (in_array($response->status, $this->failureStatuses, true)) {
            $this->recordFailure($halfOpen);
            return $response;
        }
        $this->failures = 0;
        $this->openedAt = null;
        $this->probeInProgress = false;
        return $response;
    }

    private function recordFailure(bool $halfOpen): void
    {
        $this->probeInProgress = false;
        if ($halfOpen || ++$this->failures >= $this->failureThreshold) {
            $this->openedAt = hrtime(true) / 1_000_000_000;
        }
    }
}