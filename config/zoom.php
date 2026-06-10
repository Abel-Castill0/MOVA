<?php

return [
    'account_id'    => env('ZOOM_ACCOUNT_ID'),
    'client_id'     => env('ZOOM_CLIENT_ID'),
    'client_secret' => env('ZOOM_CLIENT_SECRET'),
    // Zoom user email or "me" — used as the meeting host.
    // Set ZOOM_EMAIL to the email of your Zoom account, or leave as "me".
    'user_id'       => env('ZOOM_EMAIL', 'me'),
    'timezone'      => env('ZOOM_TIMEZONE', 'America/Lima'),
];
