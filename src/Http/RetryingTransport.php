<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

use CommunitySDKs\Exception\TransportException;

final readonly class RetryingTransport implements Transport
{
    /** @param list<int> $retryStatuses @param list<string> $retryMethods */
    public function __construct(
        private Transport $inner,
        private int $maxRetries = 2,
        private float $baseDelaySeconds = 0.1,
        private float $maxDelaySeconds = 5.0,
        private array $retryStatuses = [429, 502, 503, 504],
        private array $retryMethods = ['GET', 'HEAD', 'OPTIONS', 'TRACE', 'PUT', 'DELETE'],
        private ?RetryBudget $retryBudget = null,
    ) {
        if ($maxRetries < 0 || !is_finite($baseDelaySeconds) || !is_finite($maxDelaySeconds) || $baseDelaySeconds < 0 || $maxDelaySeconds < 0) {
            throw new \InvalidArgumentException('Retry settings must be non-negative and finite.');
        }
    }

    public function send(Request $request): Response
    {
        $retries = in_array(strtoupper($request->method), $this->retryMethods, true) ? $this->maxRetries : 0;
        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->inner->send($request);
            } catch (TransportException $e) {
                if ($attempt >= $retries) throw $e;
                if ($this->retryBudget !== null && !$this->retryBudget->consume()) throw $e;
                $this->pause($attempt);
                continue;
            }
            if ($attempt >= $retries || !in_array($response->status, $this->retryStatuses, true)) return $response;
            if ($this->retryBudget !== null && !$this->retryBudget->consume()) return $response;
            $this->pause($attempt, $response->header('Retry-After'));
        }
    }

    private function pause(int $attempt, ?string $retryAfter = null): void
    {
        $delay = min($this->maxDelaySeconds, $this->baseDelaySeconds * (2 ** $attempt));
        if ($retryAfter !== null) {
            if (is_numeric($retryAfter)) {
                $delay = min($this->maxDelaySeconds, max(0.0, (float) $retryAfter));
            } else {
                $retryAt = strtotime($retryAfter);
                if ($retryAt !== false) $delay = min($this->maxDelaySeconds, max(0.0, (float) ($retryAt - time())));
            }
        }
        if ($delay > 0) usleep((int) ceil($delay * 1_000_000));
    }
}