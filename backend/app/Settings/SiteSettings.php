<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Settings;

use App\Models\Site;

/**
 * Effective settings for one site: site override, else organisation value, else environment
 * default. Nothing is copied into the site; un-overridden keys always follow the organisation.
 */
final class SiteSettings
{
    public function __construct(
        private readonly SettingsRepository $repository,
        private readonly Site $site,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $definition = SettingsRegistry::get($key);

        if ($definition->siteOverridable) {
            $override = $this->repository->siteValues($this->site)[$key] ?? null;

            if ($override !== null) {
                return $this->repository->toConfigValue($definition, $override);
            }
        }

        return $this->repository->organisationValue($key) ?? $default;
    }

    public function isOverridden(string $key): bool
    {
        return ($this->repository->siteValues($this->site)[$key] ?? null) !== null;
    }
}
