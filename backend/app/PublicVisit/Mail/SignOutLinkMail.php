<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Secure sign-out link for a visitor who gave an email address (brief §17). Sent after the
 * response, never queued, so the plain token is not stored in the jobs table.
 */
class SignOutLinkMail extends Mailable
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $siteName,
        public readonly string $url,
        public readonly int $validHours,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Abmelden, wenn Sie gehen: :site', ['site' => $this->siteName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.visitor.signOutLink');
    }
}
