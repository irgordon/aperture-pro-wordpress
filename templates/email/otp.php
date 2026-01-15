<?php
return [
    'subject' => 'Your verification code',
    'body' => "Hello,\n\n"
        . "Your verification code is: {{code}}\n\n"
        . "This code is for {{context}} and will expire in {{expires_minutes}} minutes.\n\n"
        . "If you did not request this code, please ignore this message.\n\n"
        . "Thanks,\nThe Aperture Pro Team",
];
