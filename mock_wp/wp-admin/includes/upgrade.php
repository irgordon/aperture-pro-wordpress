<?php function dbDelta($sql) {
    if (preg_match("/CREATE TABLE (\S+)/", $sql, $matches)) {
        echo "Creating table: " . $matches[1] . "\n";
        if (strpos($sql, "payment_status") !== false) {
             echo "  - Found column: payment_status\n";
        }
        if (strpos($sql, "ap_payment_events") !== false) {
             echo "  - Found table: ap_payment_events\n";
        }
        if (strpos($sql, "ap_email_queue") !== false) {
             echo "  - Found table: ap_email_queue\n";
        }
    }
} ?>