<?php

namespace AperturePro\Auth;

class CookieService {

    const COOKIE_NAME = 'ap_client_session';

    public static function setClientSession(int $clientId, int $projectId): void {
        $data = [
            'client_id'  => $clientId,
            'project_id' => $projectId,
            'issued_at'  => time(),
        ];

        $encoded = base64_encode(json_encode($data));

        setcookie(
            self::COOKIE_NAME,
            $encoded,
            [
                'expires'  => time() + 86400,
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    public static function getClientSession(): ?array {
        if (empty($_COOKIE[self::COOKIE_NAME])) {
            return null;
        }

        $decoded = json_decode(base64_decode($_COOKIE[self::COOKIE_NAME]), true);

        if (!is_array($decoded) || empty($decoded['client_id']) || empty($decoded['project_id'])) {
            return null;
        }

        return $decoded;
    }

    public static function clear(): void {
        setcookie(self::COOKIE_NAME, '', time() - 3600, '/');
    }
}
