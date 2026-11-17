<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Pages;

use App\Models\Site;
use App\Models\User;
use App\Settings\Filament\SettingFields;
use App\Settings\SettingDefinition;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsRepository;
use App\Support\ForkPermissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * Per-site overrides. An empty field means the site follows the organisation setting; nothing
 * is copied into the site, so later organisation changes reach every site that doesn't override.
 *
 * @property-read Schema $form
 */
class ManageSiteSettings extends Page
{
    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 91;

    protected static ?string $slug = 'site-settings';

    #[Url(as: 'site')]
    public ?int $siteParam = null;

    #[Locked]
    public ?int $siteId = null;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(ForkPermissions::MANAGE_SITE_SETTINGS) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('Standorteinstellungen');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Standorteinstellungen');
    }

    public function mount(): void
    {
        $site = ($this->siteParam !== null ? Site::query()->find($this->siteParam) : null)
            ?? Site::query()->orderBy('name')->first();

        $this->siteId = $site?->getKey();
        $this->siteParam = $this->siteId;

        $stored = $site !== null ? app(SettingsRepository::class)->siteValues($site) : [];
        $state = ['site_id' => $this->siteId];

        foreach (SettingsRegistry::siteOverridable() as $key => $definition) {
            $state[$definition->field()] = $stored[$key] ?? null;
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $repository = app(SettingsRepository::class);

        return $schema
            ->statePath('data')
            ->components([
                Select::make('site_id')
                    ->label(__('Standort'))
                    ->options(fn (): array => Site::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->redirect(static::getUrl(['site' => $state]))),
                Section::make(__('Abweichungen von der Organisation'))
                    ->description(__('Leere Felder übernehmen den Wert der Organisation.'))
                    ->visible(fn (): bool => $this->siteId !== null)
                    ->columns(2)
                    ->schema(array_values(array_map(
                        fn (SettingDefinition $definition) => SettingFields::make(
                            $definition,
                            required: false,
                            inheritedHint: $this->inheritedHint($definition, $repository),
                        ),
                        SettingsRegistry::siteOverridable(),
                    ))),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(__('Speichern'))
                            ->submit('save')
                            ->visible(fn (): bool => $this->siteId !== null),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $site = Site::query()->findOrFail($this->siteId);
        $state = $this->form->getState();
        $values = [];

        foreach (SettingsRegistry::siteOverridable() as $key => $definition) {
            $values[$key] = $state[$definition->field()] ?? null;
        }

        $changes = app(SettingsRepository::class)->saveSite($site, $values, auth()->user());

        Notification::make()
            ->success()
            ->title($changes === [] ? __('Keine Änderungen.') : __('Einstellungen gespeichert.'))
            ->send();
    }

    private function inheritedHint(SettingDefinition $definition, SettingsRepository $repository): string
    {
        $value = $repository->organisationValue($definition->key);

        if ($value === null || $value === '') {
            return __('Organisation: nicht gesetzt.');
        }

        if ($definition->type === SettingDefinition::TYPE_IMAGE) {
            return __('Leer lassen, um das Logo der Organisation zu verwenden.');
        }

        if ($definition->type === SettingDefinition::TYPE_USER) {
            $user = User::query()->find($value);
            $value = $user ? trim($user->first_name.' '.$user->name) : '—';
        }

        return __('Organisation: :value', ['value' => Str::limit((string) $value, 80)]);
    }
}
