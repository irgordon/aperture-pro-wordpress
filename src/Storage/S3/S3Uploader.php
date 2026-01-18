<?php
declare(strict_types=1);

namespace AperturePro\Storage\S3;

use Aws\S3\S3Client;
use Aws\S3\MultipartUploader;
use Aws\Exception\MultipartUploadException;
use AperturePro\Storage\Upload\UploaderInterface;
use AperturePro\Storage\Upload\UploadRequest;
use AperturePro\Storage\Upload\UploadResult;
use AperturePro\Storage\Retry\RetryExecutor;

final class S3Uploader implements UploaderInterface
{
    public function __construct(
        private readonly S3Client $client,
        private readonly RetryExecutor $retry,
        private readonly string $bucket,
        private readonly string $defaultAcl = 'private'
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
        if (!is_readable($request->localPath)) {
            throw new \RuntimeException("File not readable: {$request->localPath}");
        }

        $size = $request->sizeBytes ?? filesize($request->localPath);

        // 32MB threshold for multipart
        if ($size >= 32 * 1024 * 1024) {
            return $this->multipartUpload($request, $size);
        }

        return $this->streamUpload($request, $size);
    }

    private function streamUpload(UploadRequest $request, ?int $size): UploadResult
    {
        $startTime = microtime(true);
        $key = ltrim($request->destinationKey, '/');

        $this->retry->run(function () use ($request, $key) {
            $params = [
                'Bucket' => $this->bucket,
                'Key'    => $key,
                'Body'   => fopen($request->localPath, 'r'),
                'ACL'    => $request->metadata['acl'] ?? $this->defaultAcl,
            ];

            if ($request->contentType) {
                $params['ContentType'] = $request->contentType;
            }

            $this->client->putObject($params);
        });

        $duration = (microtime(true) - $startTime) * 1000;
        $url = $this->client->getObjectUrl($this->bucket, $key);

        return new UploadResult(
            url: $url,
            provider: 'S3',
            objectKey: $key,
            etag: null,
            bytes: $size,
            durationMs: $duration
        );
    }

    private function multipartUpload(UploadRequest $request, ?int $size): UploadResult
    {
        $startTime = microtime(true);
        $key = ltrim($request->destinationKey, '/');

        $this->retry->run(function () use ($request, $key) {
            $uploader = new MultipartUploader($this->client, $request->localPath, [
                'bucket' => $this->bucket,
                'key'    => $key,
                'acl'    => $request->metadata['acl'] ?? $this->defaultAcl,
                'before_initiate' => function (\Aws\Command $command) use ($request) {
                    if ($request->contentType) {
                        $command['ContentType'] = $request->contentType;
                    }
                },
            ]);

            try {
                $uploader->upload();
            } catch (MultipartUploadException $e) {
                throw new \RuntimeException('S3 Multipart Upload failed: ' . $e->getMessage(), 0, $e);
            }
        });

        $duration = (microtime(true) - $startTime) * 1000;
        $url = $this->client->getObjectUrl($this->bucket, $key);

        return new UploadResult(
            url: $url,
            provider: 'S3',
            objectKey: $key,
            etag: null,
            bytes: $size,
            durationMs: $duration
        );
    }
}
