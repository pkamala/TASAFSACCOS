<?php

return [
    'delivery_transports' => ['smtp', 'ses', 'postmark', 'resend', 'mailgun'],
    'monthly_email_enabled' => (bool) env('MONTHLY_STATEMENT_EMAIL_ENABLED', false),
];
