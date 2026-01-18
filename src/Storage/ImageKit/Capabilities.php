<?php
declare(strict_types=1);

namespace AperturePro\Storage\ImageKit;

use ImageKit\ImageKit;

final class Capabilities
{
    private static ?bool $supportsStreams = null;

    public static function supportsStreams(ImageKit $client): bool
    {
        if (self::$supportsStreams !== null) {
            return self::$supportsStreams;
        }

        try {
            $ref = new \ReflectionMethod($client, 'upload');
            $params = $ref->getParameters();

            foreach ($params as $param) {
                if ($param->getName() === 'file') {
                    self::$supportsStreams = true;
                    return true;
                }
            }
        } catch (\Throwable) {
            // Fail soft
        }

        self::$supportsStreams = false;
        return false;
    }
}
