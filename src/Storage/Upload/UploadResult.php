<?php
declare(strict_types=1);

namespace AperturePro\Storage\Upload;

final class UploadResult
{
    public function __construct(
        public readonly string $url,
        public readonly string $provider,
        public readonly string $objectKey,
        public readonly ?string $etag = null,
        public readonly ?int $bytes = null,
        public readonly ?float $durationMs = null
    ) {}
}
