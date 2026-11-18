<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\Kiosks;

use App\Badges\BadgePrinter;
use App\Filament\Resources\Kiosks\Pages\CreateKiosk;
use App\Filament\Resources\Kiosks\Pages\EditKiosk;
use App\Filament\Resources\Kiosks\Pages\ListKiosks;
use App\Kiosk\Kiosk;
use App\Kiosk\KioskDevices;
use App\Models\Site;
use App\Settings\SettingsRepository;
use App\VisitorWorkflow\Models\VisitorType;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class KioskResource extends Resource
{
    protected static ?string $model = Kiosk::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-device-tablet';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return __('Besucherablauf');
    }

    public static function getNavigationLabel(): string
    {
        return __('Kiosks');
    }

    public static function getModelLabel(): string
    {
        return __('Kiosk');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Kiosks');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(120)
                    ->helperText(__('Z. B. „Empfang Haupteingang“. Nur für die Verwaltung sichtbar.')),
                Select::make('site_id')
                    ->label(__('Standort'))
                    ->options(fn (): array => Site::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->live()
                    ->native(false),
                TextInput::make('idle_timeout_seconds')
                    ->label(__('Zurücksetzen nach Inaktivität (Sekunden)'))
                    ->integer()
                    ->minValue(30)
                    ->maxValue(600)
                    ->default(90)
                    ->required(),
                Toggle::make('is_staffed')
                    ->label(__('Am Empfang besetzt'))
                    ->helperText(__('Für die spätere Abmeldung am Kiosk: an besetzten Kiosks dürfen Namensvorschläge angezeigt werden.')),
                Select::make('badge_printer_id')
                    ->label(__('Etikettendrucker'))
                    ->options(fn (Get $get): array => BadgePrinter::query()
                        ->where('site_id', $get('site_id'))->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder(__('Standarddrucker des Standorts'))
                    ->helperText(__('Ausweise von diesem Kiosk werden hier gedruckt. Ohne Auswahl: der Standarddrucker des Standorts, falls vorhanden.'))
                    ->native(false),
                TextEntry::make('setup_warning')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->color('warning')
                    ->visible(fn (?Kiosk $record): bool => $record !== null && self::receptionContactMissing($record))
                    ->state(fn (): string => __('An diesem Standort gibt es Besuchertypen ohne Pflicht-Gastgeber, aber keinen Empfangskontakt. Solche Besucher können sich am Kiosk nicht anmelden, bis unter Standorteinstellungen ein Empfangskontakt gesetzt ist.')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->with('site'))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('site.name')->label(__('Standort')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->state(fn (Kiosk $record): string => $record->status())
                    ->formatStateUsing(fn (string $state): string => self::statusLabels()[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'enrolled' => 'success',
                        'awaiting_enrolment' => 'warning',
                        'revoked' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('last_seen_at')->label(__('Zuletzt aktiv'))->since()->placeholder('—'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([CreateAction::make()])
            ->emptyStateActions([CreateAction::make()]);
    }

    /**
     * Issue a new one-time setup code and show it once.
     */
    public static function setupCodeAction(): Action
    {
        return Action::make('setupCode')
            ->label(__('Einrichtungscode erzeugen'))
            ->icon('heroicon-o-key')
            ->requiresConfirmation()
            ->modalDescription(__('Ein neuer Code trennt ein bereits verbundenes Gerät. Der Code ist 24 Stunden gültig und kann einmal verwendet werden.'))
            ->authorize(fn (Kiosk $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (Kiosk $record): void {
                $code = app(KioskDevices::class)->issueEnrolmentCode($record);

                Notification::make()
                    ->success()
                    ->persistent()
                    ->title(__('Einrichtungscode: :code', ['code' => $code]))
                    ->body(__('Öffnen Sie :url auf dem Gerät und geben Sie den Code ein. Er wird nur jetzt angezeigt.', ['url' => route('kiosk.enrol')]))
                    ->send();
            });
    }

    public static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('Sperren'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('Das Gerät kann sofort keine Anmeldungen mehr annehmen. Mit einem neuen Einrichtungscode kann es wieder verbunden werden.'))
            ->visible(fn (Kiosk $record): bool => ! $record->isRevoked())
            ->authorize(fn (Kiosk $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (Kiosk $record): void {
                app(KioskDevices::class)->revoke($record);
                Notification::make()->success()->title(__('Kiosk gesperrt.'))->send();
            });
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            'enrolled' => __('Verbunden'),
            'awaiting_enrolment' => __('Wartet auf Einrichtung'),
            'not_enrolled' => __('Nicht eingerichtet'),
            'revoked' => __('Gesperrt'),
        ];
    }

    /**
     * A visitor type at this kiosk's site may end up without a chosen host, but the site has
     * no reception contact to fall back to.
     */
    public static function receptionContactMissing(Kiosk $kiosk): bool
    {
        $site = $kiosk->site;

        if ($site === null) {
            return false;
        }

        $hostless = VisitorType::query()->availableAt($site)
            ->where('host_requirement', '!=', VisitorType::HOST_REQUIRED)
            ->exists();

        return $hostless && ! app(SettingsRepository::class)->forSite($site)->get('organisation.reception_host_user_id');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKiosks::route('/'),
            'create' => CreateKiosk::route('/create'),
            'edit' => EditKiosk::route('/{record}/edit'),
        ];
    }
}
