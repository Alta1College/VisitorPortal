<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Messaging;

use App\Messaging\Console\SendTestMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

/**
 * Mail delivery options beyond Laravel's built-in ones. MAIL_MAILER=microsoft-graph sends
 * through Microsoft Graph with OAuth (see docs/fork/microsoft-graph-mail.md).
 */
class MessagingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mail::extend('microsoft-graph', fn (array $config): MicrosoftGraphTransport => new MicrosoftGraphTransport(
            tenantId: (string) ($config['tenant_id'] ?? ''),
            clientId: (string) ($config['client_id'] ?? ''),
            clientSecret: (string) ($config['client_secret'] ?? ''),
            sender: (string) ($config['sender'] ?? config('mail.from.address', '')),
            authority: (string) ($config['authority'] ?? 'https://login.microsoftonline.com'),
            graphEndpoint: (string) ($config['graph_endpoint'] ?? 'https://graph.microsoft.com'),
            timeout: (int) ($config['timeout'] ?? 15),
        ));

        if ($this->app->runningInConsole()) {
            $this->commands([SendTestMail::class]);
        }
    }
}
