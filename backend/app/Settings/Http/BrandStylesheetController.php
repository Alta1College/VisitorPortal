<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Settings\Http;

use App\Settings\BrandStylesheet;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class BrandStylesheetController
{
    public function __invoke(Request $request): Response
    {
        $versioned = $request->query('v') === BrandStylesheet::version();

        return response(BrandStylesheet::css(), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            // A versioned URL never changes content, so it can be cached for a long time.
            'Cache-Control' => $versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
        ]);
    }
}
