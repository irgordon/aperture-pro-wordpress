<?php

namespace AperturePro\Auth;

use AperturePro\Helpers\Logger;

class MagicLinkService
{
    const TRANSIENT_PREFIX = 'ap_magic_link_';
    const DEFAULT_TTL = 3600;

    public static function create(
        int $projectId,
        int $clientId,
        string $purpose = 'portal_access',
        int $ttl = self::DEFAULT_TTL
    ): string {
        $token = bin2hex(random_bytes(32));

        $payload = [
            'project_id' => $projectId,
            'client_id'  => $clientId,
            'purpose'    => $purpose,
            'issued_at'  => time(),
        ];

        set_transient(self::TRANSIENT_PREFIX . $token, $payload, $ttl);

        Logger::log(
            'info',
            'magic_link',
            'Magic link created',
            [
                'project_id' => $projectId,
                'client_id'  => $clientId,
                'purpose'    => $purpose,
            ]
        );

        return $token;
    }

    public static function consume(string $token): ?array
    {
        $key = self::TRANSIENT_PREFIX . $token;
        $payload = get_transient($key);

        if (!$payload || !is_array($payload)) {
            Logger::log(
                'warning',
                'magic_link',
                'Magic link invalid or expired',
                ['token' => $token]
            );
            return null;
        }

        delete_transient($key);

        Logger::log(
            'info',
            'magic_link',
            'Magic link consumed',
            [
                'project_id' => $payload['project_id'],
                'client_id'  => $payload['client_id'],
            ]
        );

        return $payload;
    }
}
