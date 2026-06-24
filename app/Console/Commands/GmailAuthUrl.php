<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GmailAuthUrl extends Command
{
    protected $signature   = 'mova:gmail-auth-url';
    protected $description = 'Generate the Gmail OAuth authorization URL to obtain a refresh token';

    public function handle(): int
    {
        $clientId = config('services.gmail.client_id');

        if (!$clientId) {
            $this->error('GMAIL_CLIENT_ID is not configured. Add it to your .env file first.');
            return self::FAILURE;
        }

        $params = http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => 'http://localhost',
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/gmail.send',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ]);

        $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;

        $this->newLine();
        $this->info('=== Gmail API Authorization ===');
        $this->newLine();
        $this->comment('Step 1 — Open this URL in your browser:');
        $this->newLine();
        $this->line('  ' . $url);
        $this->newLine();
        $this->comment('Step 2 — Approve the Gmail permission ("Send email on your behalf").');
        $this->comment('         Google will redirect to http://localhost?code=CODE&...');
        $this->comment('         The page will fail to load — that is expected.');
        $this->comment('         Copy the "code" value from the browser URL bar.');
        $this->newLine();
        $this->comment('Step 3 — Exchange the code for a refresh token:');
        $this->newLine();
        $this->line('  php artisan mova:gmail-exchange-code YOUR_CODE_HERE');
        $this->newLine();
        $this->comment('Then add GMAIL_REFRESH_TOKEN to Railway variables (never commit it to git).');
        $this->newLine();

        return self::SUCCESS;
    }
}
