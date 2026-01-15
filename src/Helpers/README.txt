Notes on Integration and Behavior

The DownloadController relies on ZipStreamService::streamByToken($token) to perform the heavy lifting of validating tokens, preparing the ZIP, emitting headers, and streaming the file. The controller handles the REST response fallback if streaming fails. If you prefer the streaming service to exit after sending the ZIP, that behavior is acceptable; the controller is written to handle both cases gracefully.

Logger writes to the ap_logs table. It uses $wpdb->insert() and will fall back to error_log() if the DB insert fails. This keeps logging non-fatal and resilient.

ErrorHandler::traceId() provides a stable trace id for correlating logs and REST error responses. It uses random_bytes() with a fallback to uniqid().

Utils contains small helpers used across controllers and services (JSON decoding, type normalization, timestamps). These are intentionally minimal and dependency-free.

Nonce wraps WP nonce creation and verification. It returns safe defaults if WP functions are not available (useful in unit tests or CLI contexts).

Update 1:

New helper: src/Helpers/Crypto.php — provides encrypt() and decrypt() helpers using OpenSSL AES-256-CBC (with secure IV handling) and a fallback to Sodium if available. The encryption key is derived from WordPress secret constants (AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY) to avoid storing a separate key. This keeps keys encrypted at rest in the DB and decryptable only on the same site instance.

Updated admin sanitization: src/Admin/AdminUI.php — sanitize_options() now encrypts sensitive fields (cloud_api_key, webhook_secret) before saving them to the aperture_pro_settings option. Non-sensitive fields are logged as before; sensitive values are never logged.

Updated webhook verification: src/REST/PaymentController.php — when reading the webhook secret from config/options, the controller now decrypts it using Crypto::decrypt() before passing it to PaymentService::verifySignature().

Bootstrap updated: aperture-pro.php — full plugin bootstrap updated to include the new helper and to initialize admin UI and theme modules. It also ensures classes are loaded (Composer autoload or manual require) and registers cron hooks and REST controllers as before.

Security and operational notes:

The encryption key is derived from WP salts; if you migrate the DB to another site, encrypted values will not decrypt unless the same salts are used. This is intentional for security.

For multi-server deployments, ensure the same AUTH_KEY/SECURE_AUTH_KEY/LOGGED_IN_KEY values are present on all nodes.

Consider using a dedicated secrets manager (KMS, Vault) for production-grade key management. This helper is a pragmatic, secure improvement over plaintext storage.
