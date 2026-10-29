<?php

declare(strict_types=1);
namespace CommunitySDKs\Http;

use CommunitySDKs\Exception\SdkException;

final readonly class Upload
{
    public function __construct(
        public string $contents,
        public string $filename,
        public string $contentType = 'application/octet-stream',
    ) {
        if ($filename === '' || preg_match('/[\r\n]/', $filename)) {
            throw new SdkException('Upload filename must be non-empty and cannot contain line breaks.');
        }
        if (preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~i', $contentType) !== 1) {
            throw new SdkException('Upload content type must be a valid media type.');
        }
    }

    public static function fromFile(string $path, ?string $filename = null, ?string $contentType = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new SdkException("Cannot read upload file: {$path}");
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new SdkException("Could not read upload file: {$path}");
        }
        $name = $filename ?? basename($path);
        $type = $contentType;
        if ($type === null && function_exists('mime_content_type')) {
            $detected = mime_content_type($path);
            $type = is_string($detected) ? $detected : null;
        }
        return new self($contents, $name, $type ?? 'application/octet-stream');
    }
}