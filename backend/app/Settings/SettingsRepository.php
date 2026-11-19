<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Settings;

use App\Audit\AuditLogger;
use App\Models\Site;
use App\Models\User;
use App\Settings\Models\AppSetting;
use App\Settings\Models\SiteSetting;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads and writes organisation and site settings and overlays organisation values onto
 * config(). Lookup order: site override -> organisation setting -> environment default.
 */
class SettingsRepository
{
    public const IMAGE_DISK = 'public';

    public const IMAGE_DIRECTORY = 'branding';

    private const CACHE_ORGANISATION = 'fork:settings:organisation';

    private const CACHE_SITE_PREFIX = 'fork:settings:site:';

    private const CACHE_VERSION = 'fork:settings:version';

    /** @var array<string, mixed>|null Environment/config defaults captured before the first overlay. */
    private ?array $defaults = null;

    private ?string $appliedVersion = null;

    public function __construct(
        private readonly Config $config,
        private readonly Cache $cache,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Stored organisation values, keyed by setting key (raw stored form).
     *
     * @return array<string, mixed>
     */
    public function organisationValues(): array
    {
        return $this->remember(self::CACHE_ORGANISATION, fn (): array => AppSetting::query()
            ->whereIn('key', array_keys(SettingsRegistry::all()))
            ->pluck('value', 'key')
            ->all());
    }

    /**
     * Stored overrides for one site, keyed by setting key (raw stored form).
     *
     * @return array<string, mixed>
     */
    public function siteValues(Site|int $site): array
    {
        $siteId = $site instanceof Site ? $site->getKey() : $site;

        return $this->remember(self::CACHE_SITE_PREFIX.$siteId, fn (): array => SiteSetting::query()
            ->where('site_id', $siteId)
            ->whereIn('key', array_keys(SettingsRegistry::siteOverridable()))
            ->pluck('value', 'key')
            ->all());
    }

    public function forSite(Site $site): SiteSettings
    {
        return new SiteSettings($this, $site);
    }

    /**
     * The value a key has when no organisation setting is stored (environment/config default).
     */
    public function defaultValue(string $key): mixed
    {
        $this->captureDefaults();

        return $this->defaults[$key] ?? null;
    }

    /**
     * Effective organisation value for a key in config-ready form.
     */
    public function organisationValue(string $key): mixed
    {
        $stored = $this->organisationValues()[$key] ?? null;

        return $stored !== null
            ? $this->toConfigValue(SettingsRegistry::get($key), $stored)
            : $this->defaultValue($key);
    }

    /**
     * Overlay stored organisation values onto config(). Safe to call repeatedly.
     */
    public function applyOverlay(): void
    {
        $this->captureDefaults();

        $stored = $this->organisationValues();

        foreach (SettingsRegistry::all() as $key => $definition) {
            $value = array_key_exists($key, $stored) && $stored[$key] !== null
                ? $this->toConfigValue($definition, $stored[$key])
                : $this->defaults[$key];

            $this->config->set($key, $value);
        }

        $this->appliedVersion = $this->currentVersion();
    }

    /**
     * Re-apply the overlay if settings changed since this process last applied it
     * (long-running queue workers).
     */
    public function refreshIfStale(): void
    {
        if ($this->appliedVersion !== null && $this->appliedVersion === $this->currentVersion()) {
            return;
        }

        $this->applyOverlay();
    }

    /**
     * Save organisation settings. Null or blank values remove the stored value, so the key
     * falls back to its environment default.
     *
     * @param  array<string, mixed>  $values  keyed by setting key
     * @return array<string, array{old: mixed, new: mixed}> the changes made
     */
    public function saveOrganisation(array $values, ?User $actor): array
    {
        $current = $this->organisationValues();
        $changes = [];

        DB::transaction(function () use ($values, $current, $actor, &$changes): void {
            foreach ($values as $key => $value) {
                $definition = SettingsRegistry::get($key);
                $new = $definition->cast($value);
                $old = $current[$key] ?? null;

                // Saving the environment default unchanged does not pin it as a stored value.
                if ($old === null && $new !== null && $new === $definition->cast($this->defaultValue($key))) {
                    $new = null;
                }

                if ($new === $old) {
                    continue;
                }

                if ($new === null) {
                    AppSetting::query()->where('key', $key)->delete();
                } else {
                    AppSetting::query()->updateOrCreate(['key' => $key], [
                        'value' => $new,
                        'updated_by_user_id' => $actor?->getKey(),
                    ]);
                }

                $changes[$key] = ['old' => $old, 'new' => $new];
            }
        });

        if ($changes !== []) {
            $this->forget(self::CACHE_ORGANISATION);
            $this->deleteReplacedImages($changes);
            $this->applyOverlay();
            $this->audit->record('settings.organisation.updated', context: ['changes' => $changes]);
        }

        return $changes;
    }

    /**
     * Save one site's overrides. Null or blank values remove the override (inherit).
     *
     * @param  array<string, mixed>  $values  keyed by setting key
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function saveSite(Site $site, array $values, ?User $actor): array
    {
        $current = $this->siteValues($site);
        $changes = [];

        DB::transaction(function () use ($site, $values, $current, $actor, &$changes): void {
            foreach ($values as $key => $value) {
                $definition = SettingsRegistry::get($key);

                if (! $definition->siteOverridable) {
                    throw new \InvalidArgumentException("Setting [{$key}] cannot be overridden per site.");
                }

                $new = $definition->cast($value);
                $old = $current[$key] ?? null;

                if ($new === $old) {
                    continue;
                }

                if ($new === null) {
                    SiteSetting::query()->where('site_id', $site->getKey())->where('key', $key)->delete();
                } else {
                    SiteSetting::query()->updateOrCreate(['site_id' => $site->getKey(), 'key' => $key], [
                        'value' => $new,
                        'updated_by_user_id' => $actor?->getKey(),
                    ]);
                }

                $changes[$key] = ['old' => $old, 'new' => $new];
            }
        });

        if ($changes !== []) {
            $this->forget(self::CACHE_SITE_PREFIX.$site->getKey());
            $this->deleteReplacedImages($changes);
            $this->audit->record('settings.site.updated', subject: $site, site: $site, context: ['changes' => $changes]);
        }

        return $changes;
    }

    /**
     * Convert a stored value to the form config() consumers expect. Images are stored as a
     * path on the public disk and exposed as a path relative to public/ (asset()/public_path()).
     */
    public function toConfigValue(SettingDefinition $definition, mixed $stored): mixed
    {
        if ($stored === null) {
            return null;
        }

        if ($definition->type === SettingDefinition::TYPE_IMAGE) {
            return 'storage/'.ltrim((string) $stored, '/');
        }

        return $stored;
    }

    public function currentVersion(): string
    {
        try {
            return (string) $this->cache->rememberForever(self::CACHE_VERSION, fn (): string => (string) Str::uuid());
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    /**
     * Remove uploaded images that a change replaced or cleared. Only files inside the
     * settings upload directory are ever deleted.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    private function deleteReplacedImages(array $changes): void
    {
        foreach ($changes as $key => $change) {
            $old = $change['old'];

            if (SettingsRegistry::get($key)->type !== SettingDefinition::TYPE_IMAGE
                || ! is_string($old)
                || ! str_starts_with($old, self::IMAGE_DIRECTORY.'/')
                || str_contains($old, '..')) {
                continue;
            }

            try {
                Storage::disk(self::IMAGE_DISK)->delete($old);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function captureDefaults(): void
    {
        if ($this->defaults !== null) {
            return;
        }

        $this->defaults = [];

        foreach (array_keys(SettingsRegistry::all()) as $key) {
            $this->defaults[$key] = $this->config->get($key);
        }
    }

    /**
     * @param  callable(): array<string, mixed>  $loader
     * @return array<string, mixed>
     */
    private function remember(string $key, callable $loader): array
    {
        try {
            return $this->cache->rememberForever($key, $loader);
        } catch (Throwable) {
            // Database not migrated yet, or unavailable: fall back to environment defaults
            // without caching, so the next request tries again.
            return [];
        }
    }

    private function forget(string $key): void
    {
        $this->cache->forget($key);
        $this->cache->forever(self::CACHE_VERSION, (string) Str::uuid());
    }
}
