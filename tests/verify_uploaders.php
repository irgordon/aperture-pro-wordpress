<?php

// Basic mock classes
namespace Aws\S3 {
    class S3Client {
        public function __construct(array $config) {}
        public function putObject(array $args) {}
        public function getObjectUrl($b, $k) { return "https://s3.amazonaws.com/$b/$k"; }
    }
    class MultipartUploader {
        public function __construct($c, $s, $o) {}
        public function upload() {}
    }
}
namespace Aws\Exception {
    class MultipartUploadException extends \Exception {}
}

namespace Cloudinary {
    class Cloudinary {
        public function __construct(array $config) {}
        public function uploadApi() { return new UploadApi(); }
    }
    class UploadApi {
        public function upload($file, $opts) { return ['secure_url' => 'https://res.cloudinary.com/demo/image/upload/sample.jpg', 'public_id' => $opts['public_id'] ?? 'sample']; }
    }
}

namespace ImageKit {
    class ImageKit {
        public function __construct($p, $pr, $u) {}
        public function upload($params) { return (object)['url' => 'https://ik.imagekit.io/demo/sample.jpg']; }
    }
}

namespace AperturePro\Storage\Chunking {
    class ChunkedUploader {
        public function upload($path, $cb) {
            // Simulate single chunk
            return $cb(fopen('php://temp', 'r+'), 0, true);
        }
    }
}

namespace {
    require_once __DIR__ . '/../src/Storage/Upload/UploaderInterface.php';
    require_once __DIR__ . '/../src/Storage/Upload/UploadRequest.php';
    require_once __DIR__ . '/../src/Storage/Upload/UploadResult.php';
    require_once __DIR__ . '/../src/Storage/Retry/RetryExecutor.php';

    // S3
    require_once __DIR__ . '/../src/Storage/S3/S3Uploader.php';

    // Cloudinary
    require_once __DIR__ . '/../src/Storage/Cloudinary/CloudinaryUploader.php';

    // ImageKit
    require_once __DIR__ . '/../src/Storage/ImageKit/Capabilities.php';
    require_once __DIR__ . '/../src/Storage/ImageKit/ImageKitUploader.php';

    use AperturePro\Storage\S3\S3Uploader;
    use AperturePro\Storage\Cloudinary\CloudinaryUploader;
    use AperturePro\Storage\ImageKit\ImageKitUploader;
    use AperturePro\Storage\Upload\UploadRequest;
    use AperturePro\Storage\Retry\RetryExecutor;
    use AperturePro\Storage\Chunking\ChunkedUploader;

    echo "Verifying S3Uploader...\n";
    $s3Client = new \Aws\S3\S3Client([]);
    $s3Uploader = new S3Uploader($s3Client, new RetryExecutor(), 'bucket');
    $req = new UploadRequest(__FILE__, 'test.php', 'text/x-php');
    $res = $s3Uploader->upload($req);
    echo "S3 Result URL: " . $res->url . "\n";
    if ($res->provider !== 'S3') exit('S3 Provider Mismatch');

    echo "Verifying CloudinaryUploader...\n";
    $cloud = new \Cloudinary\Cloudinary([]);
    $cloudUploader = new CloudinaryUploader($cloud, new RetryExecutor());
    $res = $cloudUploader->upload($req);
    echo "Cloudinary Result URL: " . $res->url . "\n";
    if ($res->provider !== 'Cloudinary') exit('Cloudinary Provider Mismatch');

    echo "Verifying ImageKitUploader...\n";
    $ik = new \ImageKit\ImageKit('pub', 'priv', 'url');
    $ikUploader = new ImageKitUploader($ik, new RetryExecutor(), new ChunkedUploader());
    $res = $ikUploader->upload($req);
    echo "ImageKit Result URL: " . $res->url . "\n";
    if ($res->provider !== 'ImageKit') exit('ImageKit Provider Mismatch');

    echo "Verification Complete.\n";
}
