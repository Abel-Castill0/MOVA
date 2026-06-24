<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GmailApiMailService
{
    public function send(string $to, string $toName, string $subject, string $htmlBody): bool
    {
        $accessToken = $this->getAccessToken();

        if (!$accessToken) {
            return false;
        }

        $from     = config('services.gmail.from_address');
        $fromName = config('services.gmail.from_name', 'MOVA');

        $raw     = $this->buildRawMessage($from, $fromName, $to, $toName, $subject, $htmlBody, $from);
        $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $response = Http::timeout(15)
            ->withToken($accessToken)
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                'raw' => $encoded,
            ]);

        if ($response->failed()) {
            Log::error('[Gmail] Failed to send email.', [
                'status'  => $response->status(),
                'to'      => $to,
                'subject' => $subject,
            ]);
            return false;
        }

        return true;
    }

    private function getAccessToken(): ?string
    {
        $response = Http::timeout(10)
            ->asForm()
            ->post('https://oauth2.googleapis.com/token', [
                'client_id'     => config('services.gmail.client_id'),
                'client_secret' => config('services.gmail.client_secret'),
                'refresh_token' => config('services.gmail.refresh_token'),
                'grant_type'    => 'refresh_token',
            ]);

        if ($response->failed()) {
            Log::error('[Gmail] Failed to obtain access token.', [
                'status' => $response->status(),
            ]);
            return null;
        }

        return $response->json('access_token');
    }

    private function buildRawMessage(
        string $from,
        string $fromName,
        string $to,
        string $toName,
        string $subject,
        string $html,
        string $replyTo = ''
    ): string {
        $boundary = 'MOVA_' . bin2hex(random_bytes(8));

        $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $fromEncoded    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $toHeader       = $toName
            ? '=?UTF-8?B?' . base64_encode($toName) . '?= <' . $to . '>'
            : $to;

        $textBody = wordwrap(strip_tags(str_replace(['</p>', '<br>', '<br/>'], "\n", $html)), 80, "\n");

        $replyToHeader = $replyTo ? "Reply-To: {$replyTo}\r\n" : '';

        return "From: {$fromEncoded} <{$from}>\r\n"
            . "To: {$toHeader}\r\n"
            . "Subject: {$subjectEncoded}\r\n"
            . $replyToHeader
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
            . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . chunk_split(base64_encode($textBody), 76, "\r\n")
            . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . "\r\n"
            . "--{$boundary}--";
    }
}
