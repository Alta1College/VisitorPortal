<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Settings\BrandStylesheet;
use App\Settings\Models\AppSetting;
use App\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BrandStylesheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_stylesheet_exposes_configured_colours_as_css_variables(): void
    {
        app(SettingsRepository::class)->saveOrganisation([
            'organisation.colors.primary' => '#123ABC',
            'organisation.colors.background' => '#ffffff',
        ], null);

        $response = $this->get(BrandStylesheet::url());

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('--brand-primary: #123abc;', $response->getContent());
        $this->assertStringContainsString('--brand-background: #ffffff;', $response->getContent());
        $this->assertStringNotContainsString('--brand-accent', $response->getContent());
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    public function test_malformed_colour_values_never_reach_the_stylesheet(): void
    {
        // Bypass form validation entirely, as a tampered row or env value would.
        AppSetting::query()->create(['key' => 'organisation.colors.primary', 'value' => 'red;}body{display:none']);
        Cache::flush();
        app(SettingsRepository::class)->applyOverlay();

        $css = $this->get('/brand.css')->assertOk()->getContent();

        $this->assertStringNotContainsString('display:none', $css);
        $this->assertStringNotContainsString('--brand-primary', $css);
    }

    public function test_stylesheet_version_changes_when_colours_change(): void
    {
        $before = BrandStylesheet::version();

        app(SettingsRepository::class)->saveOrganisation(['organisation.colors.accent' => '#ff00ff'], null);

        $this->assertNotSame($before, BrandStylesheet::version());
    }
}
