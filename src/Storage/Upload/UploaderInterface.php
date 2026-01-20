<?php
declare(strict_types=1);

namespace AperturePro\Storage\Upload;

interface UploaderInterface
{
    /**
     * Upload a local file using the provider’s optimal strategy.
     */
    public function upload(UploadRequest $request): UploadResult;

    /**
     * Provider capability introspection.
     */
    public function supportsStreams(): bool;
    public function supportsMultipart(): bool;
    public function supportsOverwrite(): bool;
}
