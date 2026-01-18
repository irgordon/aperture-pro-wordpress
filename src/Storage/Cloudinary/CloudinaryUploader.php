<?php
declare(strict_types=1);

namespace AperturePro\Storage\Cloudinary;

use Cloudinary\Cloudinary;
use AperturePro\Storage\Upload\UploaderInterface;
use AperturePro\Storage\Upload\UploadRequest;
use AperturePro\Storage\Upload\UploadResult;
use AperturePro\Storage\Retry\RetryExecutor;

final class CloudinaryUploader implements UploaderInterface
{
    const CHUNK_SIZE = 64 * 1024 * 1024; // 64MB

    public function __construct(
        private readonly Cloudinary $cloudinary,
        private readonly RetryExecutor $retry
    ) {}

    public function supportsStreams(): bool
    {
        return true;
    }

    public function supportsMultipart(): bool
    {
        return true;
    }

    public function supportsOverwrite(): bool
    {
        return true;
    }

    public function upload(UploadRequest $request): UploadResult
    {
        $path = $request->localPath;
        if (!is_readable($path)) {
            throw new \RuntimeException("File not readable: {$path}");
        }

        $size = $request->sizeBytes ?? filesize($path);
        $target = $request->destinationKey;

        // Logic from CloudinaryStorage:
        // Preserve path structure, strip extension for public_id
        $publicId = $target;
        $ext = pathinfo($target, PATHINFO_EXTENSION);
        if ($ext) {
            $publicId = substr($target, 0, -(strlen($ext) + 1));
        }

        $params = [
            'public_id' => $publicId,
            'overwrite' => $request->overwrite,
            'resource_type' => 'auto',
        ];

        // 20MB threshold used in existing adapter
        if ($size > 20 * 1024 * 1024) {
            $params['chunk_size'] = self::CHUNK_SIZE;
        }

        $startTime = microtime(true);

        $response = $this->retry->run(function () use ($path, $params) {
            $uploadApi = $this->cloudinary->uploadApi();
            $result = $uploadApi->upload($path, $params);

            if (empty($result['secure_url'])) {
                throw new \RuntimeException('Cloudinary upload returned no URL');
            }
            return $result;
        });

        $duration = (microtime(true) - $startTime) * 1000;

        return new UploadResult(
            url: $response['secure_url'],
            provider: 'Cloudinary',
            objectKey: $response['public_id'] ?? $target,
            etag: $response['etag'] ?? null,
            bytes: isset($response['bytes']) ? (int)$response['bytes'] : $size,
            durationMs: $duration
        );
    }
}
