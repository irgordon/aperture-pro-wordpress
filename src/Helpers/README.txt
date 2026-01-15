Notes on Integration and Behavior

The DownloadController relies on ZipStreamService::streamByToken($token) to perform the heavy lifting of validating tokens, preparing the ZIP, emitting headers, and streaming the file. The controller handles the REST response fallback if streaming fails. If you prefer the streaming service to exit after sending the ZIP, that behavior is acceptable; the controller is written to handle both cases gracefully.

Logger writes to the ap_logs table. It uses $wpdb->insert() and will fall back to error_log() if the DB insert fails. This keeps logging non-fatal and resilient.

ErrorHandler::traceId() provides a stable trace id for correlating logs and REST error responses. It uses random_bytes() with a fallback to uniqid().

Utils contains small helpers used across controllers and services (JSON decoding, type normalization, timestamps). These are intentionally minimal and dependency-free.

Nonce wraps WP nonce creation and verification. It returns safe defaults if WP functions are not available (useful in unit tests or CLI contexts).
