<?php

namespace AperturePro\Storage;

use Aws\S3\S3Client;
use Aws\CloudFront\CloudFrontClient;
use Aws\CloudFront\UrlSigner;
use Aws\CommandPool;
use Aws\Exception\AwsException;
use AperturePro\Helpers\Logger;
use AperturePro\Storage\Traits\Retryable;
use AperturePro\Storage\S3\S3Uploader;
use AperturePro\Storage\Retry\RetryExecutor;
use AperturePro\Storage\Upload\UploadRequest;

/**
 * S3Storage
 *
 * S3 + optional CloudFront storage adapter.
 *
 * Responsibilities:
 *  - Store files in S3
 *  - Generate public URLs via CloudFront or S3
 *  - Generate signed URLs for private content
 */
class S3Storage extends AbstractStorage
{
    use Retryable;

    /** @var S3Client */
    protected $s3;

    /** @var CloudFrontClient|null */
    protected $cloudFront;

    /** @var string */
    protected $bucket;

    /** @var string */
    protected $region;

    /** @var string|null */
    protected $cloudfrontDomain;

    /** @var string|null */
    protected $cloudfrontKeyPairId;

    /** @var string|null */
    protected $cloudfrontPrivateKey;

    /** @var string */
    protected $defaultAcl;

    /** @var S3Uploader */
    protected $uploader;

    public function __construct(array $config)
    {
        $this->bucket = $config['bucket'] ?? '';
        $this->region = $config['region'] ?? '';
        $this->cloudfrontDomain = $config['cloudfront_domain'] ?? null;
        $this->cloudfrontKeyPairId = $config['cloudfront_key_pair_id'] ?? null;
        $this->cloudfrontPrivateKey = $config['cloudfront_private_key'] ?? null;
        $this->defaultAcl = $config['default_acl'] ?? 'private';

        if (empty($this->bucket) || empty($this->region)) {
            throw new \InvalidArgumentException('S3Storage requires bucket and region.');
        }

        $accessKey = $config['access_key'] ?? '';
        $secretKey = $config['secret_key'] ?? '';

        if (empty($accessKey) || empty($secretKey)) {
            throw new \InvalidArgumentException('S3Storage requires access_key and secret_key.');
        }

        $this->s3 = new S3Client([
            'version'     => 'latest',
            'region'      => $this->region,
            'credentials' => [
                'key'    => $accessKey,
                'secret' => $secretKey,
            ],
        ]);

        if (!empty($this->cloudfrontDomain)) {
            $this->cloudFront = new CloudFrontClient([
                'version' => 'latest',
                'region'  => $this->region,
            ]);
        }

        $this->uploader = new S3Uploader(
            $this->s3,
            new RetryExecutor(),
            $this->bucket,
            $this->defaultAcl
        );
    }

    public function getName(): string
    {
        return 'S3';
    }

    /**
     * Store a file from a local path.
     */
    public function upload(string $source, string $target, array $options = []): string
    {
        if (!is_readable($source)) {
            $msg = "S3 upload: local path not readable: $source";
            Logger::log('error', 'storage', $msg);
            throw new \RuntimeException($msg);
        }

        try {
            $request = new UploadRequest(
                localPath: $source,
                destinationKey: $target,
                contentType: $options['content_type'] ?? null,
                metadata: $options,
                sizeBytes: file_exists($source) ? filesize($source) : null
            );

            $this->uploader->upload($request);

            return $this->getUrl($target, $options);
        } catch (\Throwable $e) {
            $msg = 'S3 upload failed: ' . $e->getMessage();
            Logger::log('error', 'storage', $msg, ['target' => $target]);
            throw new \RuntimeException($msg, 0, $e);
        }
    }

    public function delete(string $target): void
    {
        try {
            $this->executeWithRetry(function() use ($target) {
                $this->s3->deleteObject([
                    'Bucket' => $this->bucket,
                    'Key'    => ltrim($target, '/'),
                ]);
            });
        } catch (\Throwable $e) {
            $msg = 'S3 delete failed: ' . $e->getMessage();
            Logger::log('error', 'storage', $msg, ['target' => $target]);
            throw new \RuntimeException($msg, 0, $e);
        }
    }

    public function exists(string $target): bool
    {
        try {
            return $this->executeWithRetry(function() use ($target) {
                return $this->s3->doesObjectExist($this->bucket, ltrim($target, '/'));
            });
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function existsMany(array $targets): array
    {
        $results = [];
        $targetList = array_values($targets);
        $commands = [];

        foreach ($targetList as $target) {
            $commands[] = $this->s3->getCommand('HeadObject', [
                'Bucket' => $this->bucket,
                'Key'    => ltrim($target, '/'),
            ]);
            // Default to false
            $results[$target] = false;
        }

        try {
            $pool = new CommandPool($this->s3, $commands, [
                'concurrency' => 25,
                'fulfilled' => function ($result, $iteratorId) use (&$results, $targetList) {
                    $target = $targetList[$iteratorId];
                    $results[$target] = true;
                },
                'rejected' => function ($reason, $iteratorId) use (&$results, $targetList) {
                    // If it's a 404, it just doesn't exist.
                    // If it's another error, we treat it as not existing for now, but could log.
                    $target = $targetList[$iteratorId];
                    $results[$target] = false;
                },
            ]);

            $promise = $pool->promise();
            $promise->wait();

        } catch (\Throwable $e) {
             Logger::log('error', 'storage', 'S3 existsMany pool failed: ' . $e->getMessage());
        }

        return $results;
    }

    public function getUrl(string $target, array $options = []): string
    {
        if (!empty($options['signed'])) {
            $ttl = $options['expires'] ?? 3600;
            return $this->getSignedUrl($target, (int) $ttl);
        }
        return $this->getPublicUrl($target);
    }

    public function getStats(): array
    {
        // S3 doesn't expose "free space". We just return healthy.
        return [
            'healthy'         => true,
            'used_bytes'      => null,
            'available_bytes' => null,
            'used_human'      => null,
            'available_human' => null,
        ];
    }

    /**
     * Helper for public URL
     */
    protected function getPublicUrl(string $target): string
    {
        $key = ltrim($target, '/');

        if (!empty($this->cloudfrontDomain)) {
            return rtrim($this->cloudfrontDomain, '/') . '/' . $key;
        }

        return sprintf(
            'https://%s.s3.%s.amazonaws.com/%s',
            $this->bucket,
            $this->region,
            $key
        );
    }

    /**
     * Helper for signed URL
     */
    protected function getSignedUrl(string $target, int $ttlSeconds = 3600): string
    {
        $key = ltrim($target, '/');
        $expires = time() + $ttlSeconds;

        try {
            // Prefer CloudFront signed URL if configured
            if (!empty($this->cloudfrontDomain) && !empty($this->cloudfrontKeyPairId) && !empty($this->cloudfrontPrivateKey)) {
                try {
                    $url = rtrim($this->cloudfrontDomain, '/') . '/' . $key;
                    $signer = new UrlSigner($this->cloudfrontKeyPairId, $this->cloudfrontPrivateKey);
                    return $signer->getSignedUrl($url, $expires);
                } catch (\Throwable $e) {
                    Logger::log('error', 'storage', 'CloudFront signed URL failed: ' . $e->getMessage(), [
                        'target' => $target,
                    ]);
                    // fall through to S3 pre-signed
                }
            }

            // Fallback: S3 pre-signed URL
            $cmd = $this->s3->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);
            $request = $this->s3->createPresignedRequest($cmd, '+' . $ttlSeconds . ' seconds');
            return (string) $request->getUri();
        } catch (\Throwable $e) {
            Logger::log('error', 'storage', 'S3 pre-signed URL failed: ' . $e->getMessage(), [
                'target' => $target,
            ]);
            // Fallback to public URL but it might not work for private objects
            return $this->getPublicUrl($target);
        }
    }

    protected function signInternal(string $path): ?string
    {
        return $this->getSignedUrl($path, 300);
    }

    protected function signManyInternal(array $paths): array
    {
        $results = [];
        foreach ($paths as $path) {
            $results[$path] = $this->signInternal($path);
        }
        return $results;
    }

    /**
     * Store raw contents. (Helper not in interface, but useful for internal logic if needed, keeping it just in case or removing if unused. I'll keep it as helper).
     */
    public function putContents(string $contents, string $target, array $options = []): void
    {
        try {
            $this->executeWithRetry(function() use ($contents, $target, $options) {
                $acl = $options['acl'] ?? $this->defaultAcl;
                $contentType = $options['content_type'] ?? null;

                $params = [
                    'Bucket' => $this->bucket,
                    'Key'    => ltrim($target, '/'),
                    'Body'   => $contents,
                    'ACL'    => $acl,
                ];

                if ($contentType) {
                    $params['ContentType'] = $contentType;
                }

                $this->s3->putObject($params);
            });
        } catch (\Throwable $e) {
            $msg = 'S3 putContents failed: ' . $e->getMessage();
            Logger::log('error', 'storage', $msg, ['target' => $target]);
            throw new \RuntimeException($msg, 0, $e);
        }
    }
}
