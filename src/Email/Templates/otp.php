<?php
return [
    'subject' => 'Your Verification Code',
    'body' => "Hi {{client_name}},\n\n"
        . "Your one‑time verification code is:\n\n"
        . "{{otp_code}}\n\n"
        . "Enter this code in the client portal to continue your download.\n\n"
        . "If you didn’t request this, you can safely ignore this email.\n\n"
        . "Best,\n{{studio_name}}",
];
