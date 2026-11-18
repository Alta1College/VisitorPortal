<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\BadgePrinters;

use App\Badges\BadgePrinter;
use App\Badges\BadgePrintException;
use App\Badges\BadgePrinting;
use App\Badges\LabelFormat;
use App\Filament\Resources\BadgePrinters\Pages\CreateBadgePrinter;
use App\Filament\Resources\BadgePrinters\Pages\EditBadgePrinter;
use App\Filament\Resources\BadgePrinters\Pages\ListBadgePrinters;
use App\Models\Site;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class BadgePrinterResource extends Resource
{
    protected static ?string $model = BadgePrinter::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-printer';

    protected static ?int $navigationSort = 45;

    public static function getNavigationGroup(): ?string
    {
        return __('Besucherablauf');
    }

    public static function getNavigationLabel(): string
    {
        return __('Ausweisdrucker');
    }

    public static function getModelLabel(): string
    {
        return __('Ausweisdrucker');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Ausweisdrucker');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(120)
                    ->helperText(__('Z. B. „Empfang Haupteingang“.')),
                Select::make('site_id')
                    ->label(__('Standort'))
                    ->options(fn (): array => Site::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->native(false),
                TextInput::make('host')
                    ->label(__('IP-Adresse oder Hostname'))
                    ->required()
                    ->maxLength(255)
                    ->regex('/^[A-Za-z0-9.\-:]+$/')
                    ->helperText(__('Vom Server aus erreichbar, z. B. über das Standort-VPN. Am besten eine feste IP-Adresse.')),
                TextInput::make('port')
                    ->label(__('Port'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->default(9100)
                    ->required(),
                Select::make('driver')
                    ->label(__('Druckertyp'))
                    ->options(BadgePrinter::driverOptions())
                    ->default('brother_ql')
                    ->required()
                    ->native(false),
                Select::make('label')
                    ->label(__('Etiketten'))
                    ->options(LabelFormat::options())
                    ->default('62x100')
                    ->required()
                    ->native(false),
                Toggle::make('is_enabled')->label(__('Aktiv'))->default(true),
                Toggle::make('is_site_default')
                    ->label(__('Standarddrucker des Standorts'))
                    ->helperText(__('Druckt Ausweise für Anmeldungen per QR-Code und für Kiosks ohne eigenen Drucker, sowie Nachdrucke am Empfang.')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->with('site'))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()
                    ->description(fn (BadgePrinter $record): ?string => $record->is_site_default ? __('Standarddrucker') : null),
                TextColumn::make('site.name')->label(__('Standort')),
                TextColumn::make('host')->label(__('Adresse'))
                    ->formatStateUsing(fn (BadgePrinter $record): string => $record->host.':'.$record->port),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->state(fn (BadgePrinter $record): string => $record->status())
                    ->formatStateUsing(fn (string $state): string => self::statusLabels()[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ok' => 'success',
                        'failing' => 'danger',
                        'disabled' => 'gray',
                        default => 'warning',
                    })
                    ->description(fn (BadgePrinter $record): ?string => $record->status() === 'failing' ? $record->last_error : null),
                TextColumn::make('last_printed_at')->label(__('Zuletzt gedruckt'))->since()->placeholder('—'),
            ])
            ->recordActions([self::testPrintAction(), self::previewAction(), EditAction::make()])
            ->toolbarActions([CreateAction::make()])
            ->emptyStateActions([CreateAction::make()]);
    }

    public static function testPrintAction(): Action
    {
        return Action::make('testPrint')
            ->label(__('Testdruck'))
            ->icon('heroicon-o-printer')
            ->authorize(fn (BadgePrinter $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (BadgePrinter $record): void {
                try {
                    app(BadgePrinting::class)->printSample($record, auth()->id());
                } catch (BadgePrintException $e) {
                    Notification::make()->danger()->persistent()->title(__('Testdruck fehlgeschlagen'))->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('Testausweis an „:printer“ gesendet.', ['printer' => $record->name]))->send();
            });
    }

    public static function previewAction(): Action
    {
        return Action::make('preview')
            ->label(__('Vorschau'))
            ->icon('heroicon-o-eye')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Schließen'))
            ->modalWidth('md')
            ->authorize(fn (BadgePrinter $record): bool => auth()->user()?->can('view', $record) ?? false)
            ->modalContent(function (BadgePrinter $record): HtmlString {
                try {
                    $png = app(BadgePrinting::class)->previewPng($record);
                } catch (BadgePrintException $e) {
                    return new HtmlString('<p>'.e($e->getMessage()).'</p>');
                }

                return new HtmlString('<img src="data:image/png;base64,'.base64_encode($png).'" alt="" style="width:100%;max-width:310px;margin:auto;display:block;border:1px solid #ddd;border-radius:8px">'
                    .'<p style="text-align:center;margin-top:8px;font-size:12px;opacity:.7">'.e(__('So wird der Ausweis gedruckt: schwarzweiß in Druckauflösung (300 dpi).')).'</p>');
            });
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            'ok' => __('Bereit'),
            'failing' => __('Fehler'),
            'disabled' => __('Deaktiviert'),
            'untested' => __('Noch nicht getestet'),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBadgePrinters::route('/'),
            'create' => CreateBadgePrinter::route('/create'),
            'edit' => EditBadgePrinter::route('/{record}/edit'),
        ];
    }
}
