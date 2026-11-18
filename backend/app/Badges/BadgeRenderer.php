<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Badges;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Renders a badge to a PNG at the label's exact printable size in dots (one CSS pixel per
 * printer dot), using the Gotenberg container that already renders the PDF badges.
 */
class BadgeRenderer
{
    public function html(BadgeContent $content, LabelFormat $format): string
    {
        return view('badges.label', ['badge' => $content, 'format' => $format])->render();
    }

    /**
     * @throws BadgePrintException
     */
    public function png(BadgeContent $content, LabelFormat $format): string
    {
        $gotenberg = config('laravel-pdf.gotenberg', []);
        $request = Http::timeout(30)->attach('files', $this->html($content, $format), 'index.html');

        if (filled($gotenberg['username'] ?? null)) {
            $request = $request->withBasicAuth((string) $gotenberg['username'], (string) ($gotenberg['password'] ?? ''));
        }

        try {
            $response = $request->post(rtrim((string) ($gotenberg['url'] ?? 'http://localhost:3000'), '/').'/forms/chromium/screenshot/html', [
                'width' => (string) $format->widthDots,
                'height' => (string) $format->lengthDots,
                'clip' => 'true',
                'format' => 'png',
            ]);
        } catch (ConnectionException $e) {
            throw new BadgePrintException(__('Der Ausweis konnte nicht erstellt werden (Gotenberg nicht erreichbar).'), 0, $e);
        }

        if (! $response->successful() || ! str_starts_with($response->body(), "\x89PNG")) {
            throw new BadgePrintException(__('Der Ausweis konnte nicht erstellt werden (Gotenberg :status).', ['status' => $response->status()]));
        }

        return $response->body();
    }
}
