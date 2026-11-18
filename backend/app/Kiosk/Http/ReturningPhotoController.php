<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Kiosk\Http;

use App\Kiosk\Kiosk;
use App\PublicVisit\ReturningVisitors;
use App\VisitorWorkflow\WorkflowRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * A returning visitor's saved photo, shown on the kiosk while they confirm "That's me" and on
 * the questions step. Enrolled kiosks only (RequireKioskDevice), and only with a short-lived
 * token bound to the kiosk's site that the sign-in screen issued for that visitor. Never cached.
 */
class ReturningPhotoController
{
    public function __invoke(Request $request, ReturningVisitors $returning): Response
    {
        $kiosk = $request->attributes->get('kiosk');
        abort_unless($kiosk instanceof Kiosk, 403);

        $path = $returning->resolvePhotoToken((string) $request->query('token', ''), (int) $kiosk->site_id);
        $disk = Storage::disk(WorkflowRecorder::EVIDENCE_DISK);

        abort_if($path === null || ! $disk->exists($path), 404);

        $mime = $disk->mimeType($path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return $disk->response($path, null, [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
