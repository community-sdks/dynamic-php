<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

interface Transport
{
    public function send(Request $request): Response;
}
