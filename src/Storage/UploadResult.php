<?php

namespace AperturePro\Storage;

class UploadResult
{
    private string $url;
    private array $metadata;

    public function __construct(string $url, array $metadata = [])
    {
        $this->url = $url;
        $this->metadata = $metadata;
    }

    /**
     * Create an UploadResult from an ImageKit response.
     *
     * @param object|array $response
     * @return self
     */
    public static function fromImageKit($response): self
    {
        $url = '';
        $metadata = [];

        if (is_object($response)) {
            $url = $response->url ?? '';
            $metadata = (array) $response;
        } elseif (is_array($response)) {
            $url = $response['url'] ?? '';
            $metadata = $response;
        }

        return new self($url, $metadata);
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
