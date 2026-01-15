<?php

namespace AperturePro\Storage;

use Aws\S3\S3Client;
use Aws\CloudFront\CloudFrontClient;
use Aws\CloudFront\UrlSigner;
use AperturePro\Helpers\Logger;

/**
 * S3Storage
 *
 * S3 + optional CloudFront storage adapter.
 *
 * Responsibilities:
 *  - Store files in S3
 *  - Generate public URLs via CloudFront or S3
 *  - Generate signed URLs for private content
 *
 * Requirements:
 *  - aws/aws-sdk-php via Composer
 *  - Config provided via constructor (bucket, region, credentials, cloudfront_domain, cloudfront_key_pair_id, cloudfront_private_key)
 */
class S3Storage implements StorageInterface
{
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

    /**
     * @param array $config
     *   - bucket (string, required)
     *   - region (string, required)
     *   - access_key (string, required)
     *   - secret_key (string, required)
     *   - cloudfront_domain (string, optional)
     *   - cloudfront_key_pair_id (string, optional)
     *   - cloudfront_private_key (string, optional, PEM string)
     *   - default_acl (string, optional, default 'private')
     */
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
    }

    /**
     * Store a file from a local path.
     *
     * @param string $localPath
     * @param string $remotePath
     * @param array  $options
     * @return bool
     */
    public function putFile(string $localPath, string $remotePath, array $options = []): bool
    {
        if (!is_readable($localPath)) {
            Logger::log('error', 'storage', 'S3 putFile: local path not readable', [
                'localPath' => $localPath,
                'remotePath' => $remotePath,
            ]);
            return false;
        }

        try {
            $acl = $options['acl'] ?? $this->defaultAcl;
            $contentType = $options['content_type'] ?? null;

            $params = [
                'Bucket'     => $this->bucket,
                'Key'        => ltrim($remotePath, '/'),
                'SourceFile' => $localPath,
                'ACL'        => $acl,
            ];

            if ($contentType) {
                $params['ContentType'] = $contentType;
            }

            $this->s3->putObject($params);
            return true;
        } catch (\Throwable $e) {
            Logger::log('error', 'storage', 'S3 putFile failed: ' . $e->getMessage(), [
                'remotePath' => $remotePath,
            ]);
            return false;
        }
    }

    /**
     * Store raw contents.
     *
     * @param string $contents
     * @param string $remotePath
     * @param array  $options
     * @return bool
     */
    public function putContents(string $contents, string $remotePath, array $options = []): bool
    {
        try {
            $acl = $options['acl'] ?? $this->defaultAcl;
            $contentType = $options['content_type'] ?? null;

            $params = [
                'Bucket' => $this->bucket,
                'Key'    => ltrim($remotePath, '/'),
                'Body'   => $contents,
                'ACL'    => $acl,
            ];

            if ($contentType) {
                $params['ContentType'] = $contentType;
            }

            $this->s3->putObject($params);
            return true;
        } catch (\Throwable $e) {
            Logger::log('error', 'storage', 'S3 putContents failed: ' . $e->getMessage(), [
                'remotePath' => $remotePath,
            ]);
            return false;
        }
    }

    /**
     * Check if an object exists.
     *
     * @param string $remotePath
     * @return bool
     */
    public function exists(string $remotePath): bool
    {
        try {
            return $this->s3->doesObjectExist($this->bucket, ltrim($remotePath, '/'));
        } catch (\Throwable $e) {
            Logger::log('warning', 'storage', 'S3 exists check failed: ' . $e->getMessage(), [
                'remotePath' => $remotePath,
            ]);
            return false;
        }
    }

    /**
     * Delete an object.
     *
     * @param string $remotePath
     * @return bool
     */
    public function delete(string $remotePath): bool
    {
        try {
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => ltrim($remotePath, '/'),
            ]);
            return true;
        } catch (\Throwable $e) {
            Logger::log('error', 'storage', 'S3 delete failed: ' . $e->getMessage(), [
                'remotePath' => $remotePath,
            ]);
            return false;
        }
    }

    /**
     * Get a public URL (non-signed).
     *
     * If CloudFront domain is configured, use it; otherwise fall back to S3 URL.
     *
     * @param string $remotePath
     * @return string
     */
    public function getPublicUrl(string $remotePath): string
    {
        $key = ltrim($remotePath, '/');

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
     * Get a signed URL for private content.
     *
     * If CloudFront key pair is configured, use CloudFront signed URL.
     * Otherwise, fall back to S3 pre-signed URL.
     *
     * @param string $remotePath
     * @param int    $ttlSeconds
     * @return string
     */
    public function getSignedUrl(string $remotePath, int $ttlSeconds = 3600): string
    {
        $key = ltrim($remotePath, '/');
        $expires = time() + $ttlSeconds;

        // Prefer CloudFront signed URL if configured
        if (!empty($this->cloudfrontDomain) && !empty($this->cloudfrontKeyPairId) && !empty($this->cloudfrontPrivateKey)) {
            try {
                $url = rtrim($this->cloudfrontDomain, '/') . '/' . $key;
                $signer = new UrlSigner($this->cloudfrontKeyPairId, $this->cloudfrontPrivateKey);
                return $signer->getSignedUrl($url, $expires);
            } catch (\Throwable $e) {
                Logger::log('error', 'storage', 'CloudFront signed URL failed: ' . $e->getMessage(), [
                    'remotePath' => $remotePath,
                ]);
                // fall through to S3 pre-signed
            }
        }

        // Fallback: S3 pre-signed URL
        try {
            $cmd = $this->s3->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);
            $request = $this->s3->createPresignedRequest($cmd, '+' . $ttlSeconds . ' seconds');
            return (string) $request->getUri();
        } catch (\Throwable $e) {
            Logger::log('error', 'storage', 'S3 pre-signed URL failed: ' . $e->getMessage(), [
                'remotePath' => $remotePath,
            ]);
            // As a last resort, return public URL (may fail if object is private)
            return $this->getPublicUrl($remotePath);
        }
    }
}
