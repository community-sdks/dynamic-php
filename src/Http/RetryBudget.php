<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

interface RetryBudget
{
    public function consume(): bool;
}