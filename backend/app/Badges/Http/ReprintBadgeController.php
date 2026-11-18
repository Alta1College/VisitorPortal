<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Badges\Http;

use App\Badges\BadgePrinting;
use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Models\Visitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Reception: print (or reprint) a visitor's badge on the site's label printer. Same
 * authorisation as upstream's PDF badge download.
 */
class ReprintBadgeController extends Controller
{
    public function __invoke(Request $request, Visit $visit, Visitor $visitor, BadgePrinting $printing): RedirectResponse
    {
        $this->authorize('viewAny', Visit::class);
        $this->authorize('view', $visit);
        $this->authorize('print', Visitor::class);

        abort_unless($visit->visitors()->whereKey($visitor->getKey())->exists(), 404);

        $printer = $printing->reprint($visit, $visitor, $request->user()?->getKey());

        return $printer !== null
            ? back()->with('success', __('Der Ausweis wird auf „:printer“ gedruckt.', ['printer' => $printer->name]))
            : back()->with('warning', __('Für diesen Standort ist kein Etikettendrucker als Standard eingerichtet.'));
    }
}
