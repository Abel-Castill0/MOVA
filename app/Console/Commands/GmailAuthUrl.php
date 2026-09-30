<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GmailAuthUrl extends Command
{
    /**
     * Único scope necesario: GmailApiMailService solo llama a
     * users.messages.send. No pedir gmail.readonly (ni otros) sin un
     * consumidor real en runtime.
     */
    public const SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    /**
     * Debe coincidir EXACTAMENTE con un "Authorized redirect URI" del cliente
     * OAuth en Google Cloud, y con el usado en mova:gmail-exchange-code.
     */
    public const REDIRECT_URI = 'http://localhost';

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
            'redirect_uri'  => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
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
        $this->comment('Step 2 — Approve the Gmail permission ("Send email" only).');
        $this->comment('         Google will redirect to ' . self::REDIRECT_URI . '?code=CODE&...');
        $this->comment('         The page will fail to load — that is expected.');
        $this->comment('         Copy the "code" value from the browser URL bar.');
        $this->newLine();
        $this->comment('Step 3 — Exchange the code for a refresh token:');
        $this->newLine();
        $this->line('  php artisan mova:gmail-exchange-code YOUR_CODE_HERE');
        $this->newLine();
        $this->warn('Step 3 is a HUMAN-INTERACTIVE SECRET step: run it yourself in a private');
        $this->warn('terminal. Its output must never be pasted into chat/agent transcripts,');
        $this->warn('CI logs or committed files.');
        $this->newLine();
        $this->comment('Owner checklist before generating a replacement refresh token (Google Cloud):');
        $this->comment('  - the Gmail API is enabled for the project;');
        $this->comment('  - this is the intended OAuth client (GMAIL_CLIENT_ID);');
        $this->comment('  - the consent screen publishing status is understood (Testing mode can');
        $this->comment('    issue time-limited refresh tokens);');
        $this->comment('  - ' . self::REDIRECT_URI . ' is registered as an authorized redirect URI;');
        $this->comment('  - the Google account you approve is the intended MOVA sender.');
        $this->newLine();

        return self::SUCCESS;
    }
}
