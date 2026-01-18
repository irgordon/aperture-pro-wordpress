<?php
declare(strict_types=1);

namespace AperturePro\Storage\Upload;

final class UploadRequest
{
    public function __construct(
        public readonly string $localPath,
        public readonly string $destinationKey,
        public readonly ?string $contentType = null,
        public readonly array $metadata = [],
        public readonly bool $overwrite = true,
        public readonly ?int $sizeBytes = null
    ) {}
}
