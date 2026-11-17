<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Pages;

use App\Settings\Filament\SettingFields;
use App\Settings\SettingDefinition;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsRepository;
use App\Support\ForkPermissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

/**
 * Organisation-wide settings: identity, branding, visitor wording, regional defaults and
 * retention. Values are stored in app_settings and overlaid onto config().
 *
 * @property-read Schema $form
 */
class ManageOrganisationSettings extends Page
{
    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'organisation-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(ForkPermissions::MANAGE_ORGANISATION_SETTINGS) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('Organisationseinstellungen');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Organisationseinstellungen');
    }

    public function mount(): void
    {
        $repository = app(SettingsRepository::class);
        $stored = $repository->organisationValues();
        $state = [];

        foreach (SettingsRegistry::all() as $key => $definition) {
            // Required settings show their effective value so the form validates on first save.
            $state[$definition->field()] = $stored[$key] ?? ($definition->required ? $repository->defaultValue($key) : null);
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('settings')->tabs([
                    Tab::make(__('Identität'))->schema($this->fields(SettingsRegistry::GROUP_IDENTITY)),
                    Tab::make(__('Erscheinungsbild'))->schema($this->fields(SettingsRegistry::GROUP_BRANDING))->columns(2),
                    Tab::make(__('Besucherführung'))->schema($this->fields(SettingsRegistry::GROUP_VISITOR_EXPERIENCE)),
                    Tab::make(__('Region'))->schema([
                        ...$this->fields(SettingsRegistry::GROUP_REGIONAL),
                        TextEntry::make('timezone')
                            ->label(__('Zeitzone'))
                            ->state(fn (): string => (string) config('app.timezone'))
                            ->helperText(__('Die Zeitzone wird bei der Installation festgelegt (APP_TIMEZONE), weil alle gespeicherten Zeiten in ihr abgelegt sind. Eine nachträgliche Änderung würde bestehende Besuchszeiten verschieben.')),
                    ]),
                    Tab::make(__('Aufbewahrung'))->schema([
                        Section::make()
                            ->description(__('Diese Fristen steuern die automatische Löschung in der Datenbank. Die Aufbewahrung von Datensicherungen wird in der Infrastruktur festgelegt.'))
                            ->schema($this->fields(SettingsRegistry::GROUP_RETENTION)),
                    ]),
                ])->persistTabInQueryString(),
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
                            ->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $values = [];

        foreach (SettingsRegistry::all() as $key => $definition) {
            $values[$key] = $state[$definition->field()] ?? null;
        }

        $changes = app(SettingsRepository::class)->saveOrganisation($values, auth()->user());

        Notification::make()
            ->success()
            ->title($changes === [] ? __('Keine Änderungen.') : __('Einstellungen gespeichert.'))
            ->send();

        // Panel branding (name, logo, colours) is rendered by the layout: reload to show it.
        if ($changes !== []) {
            $this->redirect(static::getUrl());
        }
    }

    /**
     * @return list<Field>
     */
    private function fields(string $group): array
    {
        $repository = app(SettingsRepository::class);

        return array_values(array_map(
            function (SettingDefinition $definition) use ($repository) {
                $default = $repository->defaultValue($definition->key);
                $hint = ! $definition->required
                    && $definition->type !== SettingDefinition::TYPE_IMAGE
                    && is_scalar($default) && (string) $default !== ''
                    ? __('Leer lassen für den Standardwert: :value', ['value' => Str::limit((string) $default, 80)])
                    : null;

                return SettingFields::make($definition, $definition->required, $hint);
            },
            SettingsRegistry::inGroup($group),
        ));
    }
}
