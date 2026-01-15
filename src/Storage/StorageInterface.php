<?php

namespace AperturePro\Storage;

interface StorageInterface {
    public function upload($localPath, $remotePath);
    public function delete($remotePath);
    public function getUrl($remotePath);
    public function exists($remotePath);
}
