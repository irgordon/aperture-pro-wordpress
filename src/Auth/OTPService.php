<?php

namespace AperturePro\Auth;

use AperturePro\Helpers\Logger;
use AperturePro\Email\EmailService;

/**
 * OTPService
 *
 * Generate, send, and verify short-lived OTP codes for email verification or download confirmation.
 */
class OTPService
{
    const TRANSIENT_PREFIX = 'ap_otp_';
    const TTL = 600; // 10 minutes
    const CODE_LENGTH = 6;

    /**
     * Generate an OTP for an email and send it via email.
     *
     * @param string $email
     * @param string $context optional context string (e.g., 'download')
     * @return array ['success'=>bool, 'otp_key'=>string|null]
     */
    public static function generateAndSend(string $email, string $context = 'download'): array
    {
        $email = sanitize_email($email);
        if (!is_email($email)) {
            return ['success' => false, 'message' => 'Invalid email'];
        }

        // Rate limiting: simple per-email transient (could be improved)
        $rateKey = self::TRANSIENT_PREFIX . 'rate_' . md5($email);
        $count = (int) get_transient($rateKey);
        if ($count >= 5) {
            return ['success' => false, 'message' => 'Too many OTP requests. Please try again later.'];
        }
        set_transient($rateKey, $count + 1, 60 * 60); // 1 hour window

        // Generate numeric code
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= mt_rand(0, 9);
        }

        $otpKey = bin2hex(random_bytes(16));
        $transientKey = self::TRANSIENT_PREFIX . $otpKey;

        $payload = [
            'email' => $email,
            'code' => $code,
            'context' => $context,
            'created_at' => time(),
            'expires_at' => time() + self::TTL,
        ];

        $saved = set_transient($transientKey, $payload, self::TTL);
        if (!$saved) {
            Logger::log('error', 'otp', 'Failed to save OTP transient', ['email' => $email, 'notify_admin' => true]);
            return ['success' => false, 'message' => 'Unable to generate OTP.'];
        }

        // Send OTP email (use EmailService template 'otp')
        $placeholders = [
            'code' => $code,
            'context' => ucfirst($context),
            'expires_minutes' => intval(self::TTL / 60),
        ];

        $sent = EmailService::sendTemplate('otp', $email, $placeholders);
        if (!$sent) {
            Logger::log('warning', 'otp', 'Failed to send OTP email', ['email' => $email]);
            // Keep OTP stored but inform caller
            return ['success' => true, 'otp_key' => $otpKey, 'message' => 'OTP generated but email delivery failed.'];
        }

        return ['success' => true, 'otp_key' => $otpKey];
    }

    /**
     * Verify an OTP code by otp_key and code. Deletes the transient on success.
     *
     * @param string $otpKey
     * @param string $code
     * @return bool
     */
    public static function verifyOtp(string $otpKey, string $code): bool
    {
        $transientKey = self::TRANSIENT_PREFIX . sanitize_text_field($otpKey);
        $payload = get_transient($transientKey);
        if (empty($payload) || !is_array($payload)) {
            return false;
        }

        if ((string)$payload['code'] !== (string)$code) {
            return false;
        }

        // Optionally check expiry (transient TTL handles it)
        delete_transient($transientKey);
        return true;
    }
}
