<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\QrCheckIn;

use App\Audit\AuditLogger;
use App\Models\Site;
use App\PublicVisit\PublicUrl;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;

/**
 * Mobile check-in links: issue, replace, look up, and render as a QR code (SVG, rendered with
 * bacon/bacon-qr-code, which upstream already ships for two-factor setup).
 */
class SiteQrLinks
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function forSite(Site $site): SiteQrLink
    {
        return SiteQrLink::query()->firstOrCreate(['site_id' => $site->getKey()], ['token' => $this->newToken()]);
    }

    /**
     * Replace the token: printed posters and the old address stop working at once.
     */
    public function rotate(SiteQrLink $link): SiteQrLink
    {
        $link->forceFill(['token' => $this->newToken(), 'rotated_at' => now()])->save();
        $this->audit->record('qr_link.rotated', subject: $link, site: $link->site_id);

        return $link;
    }

    /**
     * The enabled link for a token, or null. Constant-time enough: the lookup is by unique index
     * on a 190-bit random value, so timing reveals nothing useful.
     */
    public function find(string $token): ?SiteQrLink
    {
        if (preg_match('/^[A-Za-z0-9]{32,64}$/', $token) !== 1) {
            return null;
        }

        return SiteQrLink::query()->with('site')->where('token', $token)->where('is_enabled', true)->first();
    }

    public function enabledForSite(Site $site): ?SiteQrLink
    {
        return SiteQrLink::query()->where('site_id', $site->getKey())->where('is_enabled', true)->first();
    }

    /**
     * Absolute address of the link. Uses the "public address" setting when set (the address
     * visitors' phones can reach), otherwise the address this page was opened on.
     */
    public function url(SiteQrLink $link): string
    {
        return PublicUrl::to('visit/'.$link->token);
    }

    public function svg(string $url, int $size = 320): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, 1, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(0, 0, 0))),
            new SvgImageBackEnd,
        );

        $svg = (new Writer($renderer))->writeString($url);

        // Drop the XML declaration so the SVG can be inlined in HTML.
        return trim((string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg));
    }

    private function newToken(): string
    {
        return Str::random(32);
    }
}
