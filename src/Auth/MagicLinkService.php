<?php

namespace AperturePro\Auth;

class MagicLinkService {

    const TRANSIENT_PREFIX = 'ap_magiclink_';
    const TOKEN_BYTES = 32;

    public static function create(int $projectId, int $clientId, string $purpose = 'portal_access', int $lifetime = 3600): string {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        $payload = [
            'project_id' => $projectId,
            'client_id'  => $clientId,
            'purpose'    => $purpose,
            'created_at' => time(),
        ];

        set_transient(self::TRANSIENT_PREFIX . $token, $payload, $lifetime);

        return $token;
    }

    public static function consume(string $token): ?array {
        $key = self::TRANSIENT_PREFIX . $token;
        $payload = get_transient($key);

        if (!$payload) {
            return null;
        }

        delete_transient($key);

        return $payload;
    }
}
