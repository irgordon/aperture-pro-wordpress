<?php

namespace AperturePro\Auth;

class TokenService
{
    public static function generate(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function verify(string $token, string $hash): bool
    {
        return hash_equals($hash, self::hash($token));
    }
}
