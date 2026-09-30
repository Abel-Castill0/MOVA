<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * HUMAN-INTERACTIVE SECRET STEP. This command prints a refresh token. Run it
 * only in a private terminal; never from an agent session, and never paste its
 * output into chat transcripts, CI logs or committed files.
 */
class GmailExchangeCode extends Command
{
    protected $signature   = 'mova:gmail-exchange-code {code : The authorization code from the OAuth redirect}';
    protected $description = 'Exchange a Gmail OAuth authorization code for a refresh token';

    public function handle(): int
    {
        $clientId = config('services.gmail.client_id');

        if (!$clientId) {
            $this->error('GMAIL_CLIENT_ID is not configured. Add it to your .env file first.');
            return self::FAILURE;
        }

        $code = $this->argument('code');

        $this->info('Exchanging code for tokens...');

        $response = Http::timeout(15)->asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id'     => config('services.gmail.client_id'),
            'client_secret' => config('services.gmail.client_secret'),
            'code'          => $code,
            'redirect_uri'  => GmailAuthUrl::REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]);

        if ($response->failed()) {
            $error = $response->json('error_description') ?? $response->json('error') ?? 'unknown error';
            $this->error('Token exchange failed: ' . $error);
            $this->comment('Make sure you used a fresh code (codes expire in ~10 minutes).');
            return self::FAILURE;
        }

        $refreshToken = $response->json('refresh_token');

        if (!$refreshToken) {
            $this->error('No refresh_token in the response.');
            $this->comment('Hint: The auth URL must include access_type=offline and prompt=consent.');
            $this->comment('Run "php artisan mova:gmail-auth-url" to generate a correct URL.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Success! Your GMAIL_REFRESH_TOKEN is:');
        $this->newLine();
        $this->line($refreshToken);
        $this->newLine();
        $this->comment('Store it in the Azure Container Apps secret store (GMAIL_REFRESH_TOKEN).');
        $this->comment('NEVER commit it to git or paste it into chat/agent transcripts or CI logs.');
        $this->newLine();

        return self::SUCCESS;
    }
}
