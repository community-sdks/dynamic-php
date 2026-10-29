<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

final readonly class MiddlewareTransport implements Transport
{
    /** @param list<HttpMiddleware> $middleware */
    public function __construct(private Transport $inner, private array $middleware) {}

    public function send(Request $request): Response
    {
        $next = fn (Request $request): Response => $this->inner->send($request);
        foreach (array_reverse($this->middleware) as $middleware) {
            $downstream = $next;
            $next = fn (Request $request): Response => $middleware->process($request, $downstream);
        }
        return $next($request);
    }
}