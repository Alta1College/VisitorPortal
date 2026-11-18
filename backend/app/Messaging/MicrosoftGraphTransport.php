<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Messaging;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\RawMessage;

/**
 * Sends mail through Microsoft Graph with OAuth 2.0 client credentials (an Entra app
 * registration with the Mail.Send application permission), so no mailbox password or SMTP
 * AUTH is needed. The message is sent as MIME, so HTML, inline images and attachments arrive
 * exactly as Laravel built them.
 *
 * Scope the app registration to the sending mailbox (Exchange RBAC for Applications or an
 * application access policy); otherwise Mail.Send allows sending as any mailbox in the tenant.
 */
class MicrosoftGraphTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
        private readonly string $sender,
        private readonly string $authority = 'https://login.microsoftonline.com',
        private readonly string $graphEndpoint = 'https://graph.microsoft.com',
        private readonly int $timeout = 15,
    ) {
        parent::__construct();
    }

    /**
     * Graph only sends as the sending mailbox (ErrorSendAsDenied otherwise): any other From is
     * replaced by the sender, keeping the display name, and kept as Reply-To.
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($message instanceof Email && $this->sender !== '') {
            $from = $message->getFrom()[0] ?? null;

            if ($from === null || mb_strtolower($from->getAddress()) !== mb_strtolower($this->sender)) {
                $message = clone $message;
                $message->from(new Address($this->sender, $from?->getName() ?? ''));

                if ($from !== null && $message->getReplyTo() === []) {
                    $message->replyTo($from);
                }

                if ($envelope !== null) {
                    $envelope = new Envelope(new Address($this->sender), $envelope->getRecipients());
                }
            }
        }

        return parent::send($message, $envelope);
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->tenantId === '' || $this->clientId === '' || $this->clientSecret === '' || $this->sender === '') {
            throw new TransportException('Microsoft Graph mail is not configured (tenant, client ID, secret and sender are required).');
        }

        $mime = $this->withBcc($message);
        $url = rtrim($this->graphEndpoint, '/').'/v1.0/users/'.rawurlencode($this->sender).'/sendMail';

        $response = $this->post($url, $mime, $this->token());

        // A token revoked or rotated early: fetch a new one once and retry.
        if ($response->status() === 401) {
            Cache::forget($this->cacheKey());
            $response = $this->post($url, $mime, $this->token());
        }

        if (! $response->successful()) {
            throw new TransportException(sprintf('Microsoft Graph refused the message (%d %s).', $response->status(), $this->errorCode($response)));
        }
    }

    public function __toString(): string
    {
        return 'microsoft-graph://'.$this->sender;
    }

    private function post(string $url, string $mime, string $token): Response
    {
        try {
            return Http::withToken($token)
                ->timeout($this->timeout)
                ->withBody(base64_encode($mime), 'text/plain')
                ->post($url);
        } catch (ConnectionException $e) {
            throw new TransportException('Microsoft Graph could not be reached: '.$e->getMessage(), 0, $e);
        }
    }

    private function token(): string
    {
        $cached = Cache::get($this->cacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::asForm()->timeout($this->timeout)->post(
                rtrim($this->authority, '/').'/'.rawurlencode($this->tenantId).'/oauth2/v2.0/token',
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => rtrim($this->graphEndpoint, '/').'/.default',
                ],
            );
        } catch (ConnectionException $e) {
            throw new TransportException('Microsoft Entra could not be reached: '.$e->getMessage(), 0, $e);
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            // Entra's error code (e.g. invalid_client, unauthorized_client) only, never the secret.
            throw new TransportException(sprintf('Microsoft Entra refused the mail app credentials (%d %s).', $response->status(), (string) $response->json('error', 'unknown')));
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - 300);
        Cache::put($this->cacheKey(), $token, $ttl);

        return $token;
    }

    /**
     * Graph reads recipients from the MIME headers, and the prepared message has no Bcc header:
     * add envelope recipients that are not in To or Cc as Bcc.
     */
    private function withBcc(SentMessage $message): string
    {
        $raw = $message->toString();
        $original = $message->getOriginalMessage();

        if (! $original instanceof Email) {
            try {
                $original = MessageConverter::toEmail($original);
            } catch (\Throwable) {
                return $raw;
            }
        }

        $visible = array_map(
            fn (Address $address): string => mb_strtolower($address->getAddress()),
            [...$original->getTo(), ...$original->getCc()],
        );

        $bcc = array_values(array_filter(
            array_map(fn (Address $address): string => $address->getAddress(), $message->getEnvelope()->getRecipients()),
            fn (string $address): bool => ! in_array(mb_strtolower($address), $visible, true),
        ));

        return $bcc === [] ? $raw : 'Bcc: '.implode(', ', $bcc)."\r\n".$raw;
    }

    private function errorCode(Response $response): string
    {
        return (string) ($response->json('error.code') ?? 'unknown');
    }

    private function cacheKey(): string
    {
        return 'mail.microsoft-graph.token.'.hash('sha256', $this->authority.'|'.$this->tenantId.'|'.$this->clientId);
    }
}
