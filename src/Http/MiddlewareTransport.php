<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

final readonly class MiddlewareTransport implements StreamingTransport
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

    public function stream(Request $request, callable $onChunk, int $chunkSize = 16384): Response
    {
        $next = function (Request $request) use ($onChunk, $chunkSize): Response {
            if (!$this->inner instanceof StreamingTransport) {
                throw new \LogicException('The inner transport does not support streaming.');
            }
            return $this->inner->stream($request, $onChunk, $chunkSize);
        };
        foreach (array_reverse($this->middleware) as $middleware) {
            $downstream = $next;
            $next = fn (Request $request): Response => $middleware->process($request, $downstream);
        }
        return $next($request);
    }
}