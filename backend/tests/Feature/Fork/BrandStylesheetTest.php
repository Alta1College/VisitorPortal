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

    public function test_brand_colours_are_mapped_onto_the_portal_theme_with_readable_text(): void
    {
        app(SettingsRepository::class)->saveOrganisation([
            'organisation.colors.primary' => '#1e3a8a', // dark navy: white text
            'organisation.colors.accent' => '#fde047',  // light yellow: black text
        ], null);

        $css = BrandStylesheet::css();

        $this->assertMatchesRegularExpression('/html\[data-theme\] \{[^}]*--color-primary: #1e3a8a;[^}]*--color-primary-content: #ffffff;/s', $css);
        $this->assertMatchesRegularExpression('/html\[data-theme\] \{[^}]*--color-accent: #fde047;[^}]*--color-accent-content: #000000;/s', $css);
        $this->assertStringNotContainsString('--color-secondary', $css);
    }

    public function test_dark_themes_get_a_lightened_primary_that_is_readable(): void
    {
        app(SettingsRepository::class)->saveOrganisation(['organisation.colors.primary' => '#1e3a8a'], null);

        preg_match('/html\[data-theme="dark"\], html\[data-theme="true-black"\] \{[^}]*--color-primary: (#[0-9a-f]{6});/s', BrandStylesheet::css(), $m);

        $this->assertNotEmpty($m, 'dark theme override missing');
        $this->assertNotSame('#1e3a8a', $m[1]);
        $this->assertGreaterThanOrEqual(4.5, BrandStylesheet::contrast($m[1], '#18181b'));
        // A colour that is already readable on dark is left as it is.
        $this->assertSame('#fde047', BrandStylesheet::readableOnDark('#fde047'));
    }

    public function test_foreground_and_background_apply_to_the_light_theme_only_when_readable(): void
    {
        $settings = app(SettingsRepository::class);

        $settings->saveOrganisation(['organisation.colors.foreground' => '#222222', 'organisation.colors.background' => '#fafaf5'], null);
        $css = BrandStylesheet::css();
        $this->assertTrue(BrandStylesheet::surfaceColoursApply());
        $this->assertMatchesRegularExpression('/html\[data-theme="light"\] \{[^}]*--color-base-content: #222222;[^}]*--color-base-200: #fafaf5;/s', $css);
        // !important: upstream's theme scripts set the page background as an inline style.
        $this->assertMatchesRegularExpression('/html\[data-theme="light"\] body \{\s*background-color: #fafaf5 !important;/', $css);

        // Light grey text on white: unreadable, so neither colour is applied.
        $settings->saveOrganisation(['organisation.colors.foreground' => '#dddddd', 'organisation.colors.background' => '#ffffff'], null);
        $this->assertFalse(BrandStylesheet::surfaceColoursApply());
        $this->assertStringNotContainsString('--color-base-content', BrandStylesheet::css());
    }

    public function test_portal_and_login_pages_load_the_brand_stylesheet(): void
    {
        app(SettingsRepository::class)->saveOrganisation(['organisation.colors.primary' => '#0055aa'], null);

        $this->get('/login')->assertOk()->assertSee(BrandStylesheet::url(), false);
    }
}
