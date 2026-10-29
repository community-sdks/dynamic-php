<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

use Closure;
use Throwable;

final readonly class MetricsMiddleware implements HttpMiddleware
{
    /** @var Closure(Request,?Response,float,?Throwable):void */
    private Closure $recorder;

    /** @param callable(Request,?Response,float,?Throwable):void $recorder duration is in seconds */
    public function __construct(callable $recorder)
    {
        $this->recorder = Closure::fromCallable($recorder);
    }

    public function process(Request $request, callable $next): Response
    {
        $start = hrtime(true);
        try {
            $response = $next($request);
        } catch (Throwable $error) {
            ($this->recorder)($request, null, (hrtime(true) - $start) / 1_000_000_000, $error);
            throw $error;
        }
        ($this->recorder)($request, $response, (hrtime(true) - $start) / 1_000_000_000, null);
        return $response;
    }
}