<?php

namespace AperturePro\Email;

use AperturePro\Helpers\Logger;

/**
 * EmailService
 *
 * Lightweight templated email sender for Aperture Pro.
 *
 * Usage:
 *  EmailService::sendTemplate('project-created', $to, $placeholders);
 *
 * Templates are PHP files under src/Email/Templates that return an array:
 *  ['subject' => '...', 'body' => '...']
 *
 * Placeholders are simple key => value replacements in subject and body.
 */
class EmailService
{
    const TEMPLATE_PATH = __DIR__ . '/Templates/';

    /**
     * Send a templated email.
     *
     * @param string $templateName e.g., 'project-created'
     * @param string|array $to email or array of emails
     * @param array $placeholders associative replacements
     * @param array $headers optional headers for wp_mail
     * @return bool true on success, false on failure
     */
    public static function sendTemplate(string $templateName, $to, array $placeholders = [], array $headers = []): bool
    {
        $templateFile = self::TEMPLATE_PATH . $templateName . '.php';
        if (!file_exists($templateFile)) {
            Logger::log('error', 'email', 'Email template not found', ['template' => $templateName, 'notify_admin' => true]);
            return false;
        }

        // Load template
        $template = include $templateFile;
        if (!is_array($template) || empty($template['subject']) || empty($template['body'])) {
            Logger::log('error', 'email', 'Email template returned invalid structure', ['template' => $templateName, 'notify_admin' => true]);
            return false;
        }

        $subject = self::applyPlaceholders($template['subject'], $placeholders);
        $body = self::applyPlaceholders($template['body'], $placeholders);

        // Default headers
        $defaultHeaders = [
            'Content-Type: text/plain; charset=UTF-8',
        ];

        $allHeaders = array_merge($defaultHeaders, $headers);

        // Attempt to send, with a single retry on failure
        $sent = @wp_mail($to, $subject, $body, $allHeaders);

        if (!$sent) {
            // Retry once after a short pause
            sleep(1);
            $sent = @wp_mail($to, $subject, $body, $allHeaders);
        }

        if (!$sent) {
            Logger::log('error', 'email', 'Failed to send email', ['template' => $templateName, 'to' => $to, 'placeholders' => $placeholders, 'notify_admin' => true]);
            return false;
        }

        Logger::log('info', 'email', 'Email sent', ['template' => $templateName, 'to' => $to]);
        return true;
    }

    /**
     * Replace placeholders in a string.
     *
     * Placeholders are in the form {{key}}.
     */
    protected static function applyPlaceholders(string $text, array $placeholders = []): string
    {
        if (empty($placeholders)) {
            return $text;
        }

        $search = [];
        $replace = [];

        foreach ($placeholders as $k => $v) {
            $search[] = '{{' . $k . '}}';
            $replace[] = (string) $v;
        }

        return str_replace($search, $replace, $text);
    }
}
