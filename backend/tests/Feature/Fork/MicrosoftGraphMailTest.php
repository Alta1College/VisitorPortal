<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class MicrosoftGraphMailTest extends TestCase
{
    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token';

    private const SEND_URL = 'https://graph.microsoft.com/v1.0/users/visitors%40school.example/sendMail';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'mail.default' => 'microsoft-graph',
            'mail.from.address' => 'visitors@school.example',
            'mail.mailers.microsoft-graph' => [
                'transport' => 'microsoft-graph',
                'tenant_id' => 'tenant-1',
                'client_id' => 'client-1',
                'client_secret' => 'secret-value',
                'sender' => 'visitors@school.example',
            ],
        ]);
        app('mail.manager')->forgetMailers();
    }

    public function test_mail_is_sent_as_mime_with_a_cached_client_credentials_token(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok-1', 'expires_in' => 3599]),
            self::SEND_URL => Http::response(null, 202),
        ]);

        Mail::raw('Hello Pat', fn ($m) => $m->to('pat@example.org')->bcc('audit@example.org')->subject('Sign out'));
        Mail::raw('Second', fn ($m) => $m->to('sam@example.org')->subject('Again'));

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r): bool => $r->url() === self::TOKEN_URL
            && $r['grant_type'] === 'client_credentials' && $r['client_id'] === 'client-1'
            && $r['scope'] === 'https://graph.microsoft.com/.default');

        $sends = Http::recorded(fn (Request $r): bool => $r->url() === self::SEND_URL)->values();
        $this->assertCount(2, $sends);
        [$request] = $sends[0];
        $this->assertSame('Bearer tok-1', $request->header('Authorization')[0]);
        $this->assertStringStartsWith('text/plain', $request->header('Content-Type')[0]);

        $mime = base64_decode($request->body(), true);
        $this->assertStringContainsString('To: pat@example.org', $mime);
        $this->assertStringContainsString('Bcc: audit@example.org', $mime);
        $this->assertStringContainsString('Subject: Sign out', $mime);
        $this->assertStringContainsString('Hello Pat', $mime);
    }

    public function test_another_from_address_is_sent_as_the_mailbox_with_reply_to(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::SEND_URL => Http::response(null, 202),
        ]);

        Mail::raw('Hi', fn ($m) => $m->from('noreply@other.example', 'Front Office')->to('pat@example.org')->subject('Hi'));

        $mime = base64_decode(Http::recorded(fn (Request $r): bool => $r->url() === self::SEND_URL)->values()[0][0]->body(), true);
        $this->assertStringContainsString('From: Front Office <visitors@school.example>', $mime);
        $this->assertStringContainsString('Reply-To: Front Office <noreply@other.example>', $mime);
    }

    public function test_a_rejected_token_is_refreshed_once(): void
    {
        Http::fakeSequence(self::TOKEN_URL)
            ->push(['access_token' => 'old', 'expires_in' => 3599])
            ->push(['access_token' => 'new', 'expires_in' => 3599]);
        Http::fakeSequence(self::SEND_URL)
            ->push(['error' => ['code' => 'InvalidAuthenticationToken']], 401)
            ->push(null, 202);

        Mail::raw('Hi', fn ($m) => $m->to('pat@example.org')->subject('Hi'));

        $sends = Http::recorded(fn (Request $r): bool => $r->url() === self::SEND_URL)->values();
        $this->assertSame('Bearer new', $sends[1][0]->header('Authorization')[0]);
    }

    public function test_failures_name_the_error_but_never_the_secret(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_client', 'error_description' => 'AADSTS7000215'], 401)]);

        try {
            Mail::raw('Hi', fn ($m) => $m->to('pat@example.org')->subject('Hi'));
            $this->fail('Expected a transport exception.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('invalid_client', $e->getMessage());
            $this->assertStringNotContainsString('secret-value', $e->getMessage());
        }

    }

    public function test_a_mailbox_refusal_names_the_graph_error(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::SEND_URL => Http::response(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied.']], 403),
        ]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('403 ErrorAccessDenied');
        Mail::raw('Hi', fn ($m) => $m->to('pat@example.org')->subject('Hi'));
    }

    public function test_the_test_command_reports_success_and_failure(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            self::SEND_URL => Http::response(null, 202),
        ]);

        $this->artisan('visitorportal:mail:test', ['to' => 'it@example.org'])
            ->expectsOutputToContain('Test email sent to it@example.org via "microsoft-graph"')
            ->assertSuccessful();

        config(['mail.default' => 'log']);
        $this->artisan('visitorportal:mail:test', ['to' => 'it@example.org', '--mailer' => 'microsoft-graph'])
            ->expectsOutputToContain('via "microsoft-graph"')->assertSuccessful();

        $this->artisan('visitorportal:mail:test', ['to' => 'not-an-address'])->assertExitCode(2);
    }
}
