<?php
declare(strict_types=1);

namespace AperturePro\Storage\ImageKit;

use ImageKit\ImageKit;
use AperturePro\Storage\UploadResult;
use AperturePro\Storage\Retry\RetryExecutor;
use AperturePro\Storage\Chunking\ChunkedUploader;

final class ImageKitUploader
{
    public function __construct(
        private readonly ImageKit $client,
        private readonly RetryExecutor $retry,
        private readonly ChunkedUploader $chunker
    ) {}

    public function upload(string $path, string $destination, array $options = []): UploadResult
    {
        if (!is_readable($path)) {
            throw new \RuntimeException("File not readable: {$path}");
        }

        if (filesize($path) > 500 * 1024 * 1024) {
            throw new \RuntimeException('File exceeds maximum allowed size.');
        }

        $folder = dirname($destination);
        if ($folder === '.' || $folder === '/') {
            $folder = '';
        }

        return $this->retry->run(function () use ($path, $destination, $folder, $options) {

            if (Capabilities::supportsStreams($this->client)) {
                return $this->streamUpload($path, $destination, $folder, $options);
            }

            return $this->chunkedFallback($path, $destination, $folder, $options);
        });
    }

    private function streamUpload(string $path, string $destination, string $folder, array $options): UploadResult
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

        return UploadResult::fromImageKit($response);
    }

    private function chunkedFallback(string $path, string $destination, string $folder, array $options): UploadResult
    {
        return $this->chunker->upload(
            $path,
            function ($chunkStream, int $index, bool $isLast) use ($destination, $folder, $options) {

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

                return UploadResult::fromImageKit($response);
            }
        );
    }
}
