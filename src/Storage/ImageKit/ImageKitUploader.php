<?php
declare(strict_types=1);

namespace AperturePro\Storage\ImageKit;

use ImageKit\ImageKit;
use AperturePro\Storage\Upload\UploaderInterface;
use AperturePro\Storage\Upload\UploadRequest;
use AperturePro\Storage\Upload\UploadResult;
use AperturePro\Storage\Retry\RetryExecutor;
use AperturePro\Storage\Chunking\ChunkedUploader;

final class ImageKitUploader implements UploaderInterface
{
    public function __construct(
        private readonly ImageKit $client,
        private readonly RetryExecutor $retry,
        private readonly ChunkedUploader $chunker
    ) {}

    public function supportsStreams(): bool
    {
        return Capabilities::supportsStreams($this->client);
    }

    public function supportsMultipart(): bool
    {
        return false;
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
        if ($size > 500 * 1024 * 1024) {
            throw new \RuntimeException('File exceeds maximum allowed size.');
        }

        $destination = $request->destinationKey;
        $folder = dirname($destination);
        if ($folder === '.' || $folder === '/') {
            $folder = '';
        }

        // Extract tags from metadata if present
        $options = [];
        if (isset($request->metadata['tags'])) {
            $options['tags'] = $request->metadata['tags'];
        }

        $startTime = microtime(true);

        return $this->retry->run(function () use ($path, $destination, $folder, $options, $request, $startTime, $size) {

            if ($this->supportsStreams()) {
                return $this->streamUpload($path, $destination, $folder, $options, $startTime, $size);
            }

            return $this->chunkedFallback($path, $destination, $folder, $options, $startTime, $size);
        });
    }

    private function streamUpload(string $path, string $destination, string $folder, array $options, float $startTime, ?int $size): UploadResult
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Unable to open file stream.');
        }

        try {
            $params = [
                'file' => $handle,
                'fileName' => basename($destination),
                'useUniqueFileName' => false,
            ];

            if (!empty($folder)) {
                $params['folder'] = $folder;
            }

            if (!empty($options['tags'])) {
                $params['tags'] = $options['tags'];
            }

            $response = $this->client->upload($params);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return $this->toResult($response, $destination, $startTime, $size);
    }

    private function chunkedFallback(string $path, string $destination, string $folder, array $options, float $startTime, ?int $size): UploadResult
    {
        return $this->chunker->upload(
            $path,
            function ($chunkStream, int $index, bool $isLast) use ($destination, $folder, $options, $startTime, $size) {

                $params = [
                    'file' => $chunkStream,
                    'fileName' => basename($destination),
                    'useUniqueFileName' => false,
                    'customMetadata' => [
                        'chunk_index' => (string) $index,
                        'chunk_last' => $isLast ? '1' : '0',
                    ],
                ];

                if (!empty($folder)) {
                    $params['folder'] = $folder;
                }

                if (!empty($options['tags'])) {
                    $params['tags'] = $options['tags'];
                }

                $response = $this->client->upload($params);

                return $this->toResult($response, $destination, $startTime, $size);
            }
        );
    }

    private function toResult(object|array $response, string $key, float $startTime, ?int $size): UploadResult
    {
        $url = '';
        if (is_object($response)) {
            $url = $response->url ?? '';
        } elseif (is_array($response)) {
            $url = $response['url'] ?? '';
        }

        $duration = (microtime(true) - $startTime) * 1000;

        return new UploadResult(
            url: $url,
            provider: 'ImageKit',
            objectKey: $key,
            etag: null,
            bytes: $size,
            durationMs: $duration
        );
    }
}
