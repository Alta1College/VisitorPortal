<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Messaging\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one plain test message through the configured mailer, to check delivery settings.
 */
class SendTestMail extends Command
{
    protected $signature = 'visitorportal:mail:test {to : Recipient address} {--mailer= : Mailer to use instead of MAIL_MAILER (e.g. microsoft-graph)}';

    protected $description = 'Send a test email through the configured mailer.';

    public function handle(): int
    {
        $to = (string) $this->argument('to');

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Please give a valid email address.');

            return self::INVALID;
        }

        $mailer = (string) ($this->option('mailer') ?: config('mail.default'));

        try {
            Mail::mailer($mailer)->raw(
                'This is a test message from '.config('app.name').'. If you can read it, visitor emails such as sign-out links will be delivered.',
                fn ($message) => $message->to($to)->subject(config('app.name').' test email'),
            );
        } catch (Throwable $e) {
            $this->error('Sending failed via "'.$mailer.'": '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Test email sent to '.$to.' via "'.$mailer.'".');

        return self::SUCCESS;
    }
}
