<?php

namespace AperturePro\Storage\Chunking;

class ChunkedUploader
{
    private int $chunkSize;

    public function __construct(int $chunkSize = 5 * 1024 * 1024)
    {
        $this->chunkSize = $chunkSize;
    }

    /**
     * Upload a file in chunks using a callback.
     *
     * @param string $path Local file path
     * @param callable $callback Function(resource $chunkStream, int $index, bool $isLast): mixed
     * @return mixed The result of the last chunk upload
     */
    public function upload(string $path, callable $callback): mixed
    {
        $handle = fopen($path, 'rb');

        if (!$handle) {
            throw new \RuntimeException("Unable to open file: $path");
        }

        $index = 0;
        $result = null;

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, $this->chunkSize);

                // If we read nothing (EOF reached exactly before), break
                if ($chunk === false || $chunk === '') {
                    break;
                }

                // Create a temporary stream for the chunk
                $chunkStream = fopen('php://temp', 'r+');
                fwrite($chunkStream, $chunk);
                rewind($chunkStream);

                $isLast = feof($handle);

                // Execute callback
                $result = $callback($chunkStream, $index, $isLast);

                fclose($chunkStream);
                $index++;
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return $result;
    }
}
