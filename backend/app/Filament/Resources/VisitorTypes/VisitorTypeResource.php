<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\VisitorTypes;

use App\Filament\Resources\VisitorTypes\Pages\CreateVisitorType;
use App\Filament\Resources\VisitorTypes\Pages\EditVisitorType;
use App\Filament\Resources\VisitorTypes\Pages\ListVisitorTypes;
use App\PublicVisit\VisitorDetails;
use App\VisitorWorkflow\Models\VisitorType;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class VisitorTypeResource extends Resource
{
    protected static ?string $model = VisitorType::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('Besucherablauf');
    }

    public static function getNavigationLabel(): string
    {
        return __('Besuchertypen');
    }

    public static function getModelLabel(): string
    {
        return __('Besuchertyp');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Besuchertypen');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(120)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Set $set, string $operation): void {
                        if ($operation === 'create') {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')
                    ->label(__('Kennung'))
                    ->required()
                    ->maxLength(120)
                    ->alphaDash()
                    ->unique(ignoreRecord: true)
                    ->helperText(__('Technische Kennung, z. B. für Auswertungen. Bleibt beim Umbenennen gleich.')),
                Textarea::make('description')->label(__('Beschreibung'))->rows(2)->columnSpanFull(),
                Select::make('host_requirement')
                    ->label(__('Gastgeber'))
                    ->options(VisitorType::hostRequirements())
                    ->default(VisitorType::HOST_OPTIONAL)
                    ->required()
                    ->native(false),
                Select::make('sites')
                    ->label(__('Nur an diesen Standorten'))
                    ->relationship('sites', 'name')
                    ->multiple()
                    ->preload()
                    ->helperText(__('Leer lassen, um den Typ an allen Standorten anzubieten.')),
                TextInput::make('badge_label')->label(__('Bezeichnung auf dem Ausweis'))->maxLength(40),
                ColorPicker::make('badge_color')->label(__('Farbe auf dem Ausweis'))->hex()->regex('/^#[0-9a-fA-F]{6}$/'),
                Toggle::make('print_badge')->label(__('Ausweis drucken'))->default(true)
                    ->helperText(__('Druckt bei der Anmeldung einen Ausweis, wenn am Kiosk oder Standort ein Etikettendrucker eingerichtet ist.')),
                TextInput::make('sort_order')->label(__('Reihenfolge'))->integer()->minValue(0)->default(0),
                Toggle::make('is_active')->label(__('Aktiv'))->default(true),
            ]),
            self::detailFieldsSection(),
        ]);
    }

    /**
     * Which personal details this type asks for. Names are always required (a visit needs a
     * name) but can be relabelled; email, phone and company can be required, optional or hidden.
     */
    private static function detailFieldsSection(): Section
    {
        $defaults = VisitorDetails::settings();
        $rows = [];

        foreach (VisitorDetails::FIELDS as $field) {
            $configurable = in_array($field, VisitorDetails::CONFIGURABLE, true);

            $rows[] = Grid::make(2)->schema([
                Select::make("detail_fields.{$field}.mode")
                    ->label(VisitorDetails::defaultLabels()[$field])
                    ->options(VisitorDetails::modes())
                    ->default($defaults['fields'][$field]['mode'])
                    ->formatStateUsing(fn (?string $state): string => $configurable ? ($state ?? $defaults['fields'][$field]['mode']) : VisitorDetails::REQUIRED)
                    ->disabled(! $configurable)
                    ->dehydrated($configurable)
                    ->helperText($configurable ? null : __('Immer Pflichtfeld.'))
                    ->selectablePlaceholder(false)
                    ->required($configurable)
                    ->native(false),
                TextInput::make("detail_fields.{$field}.label")
                    ->label(__('Eigene Bezeichnung'))
                    ->placeholder(VisitorDetails::defaultLabels()[$field])
                    ->maxLength(80),
            ]);
        }

        $rows[] = Toggle::make('detail_fields.email_or_phone')
            ->label(__('E-Mail oder Telefon: mindestens eines angeben'))
            ->helperText(__('Gilt nur, wenn beide Felder abgefragt werden.'))
            ->default($defaults['email_or_phone'])
            ->formatStateUsing(fn ($state): bool => (bool) ($state ?? $defaults['email_or_phone']));

        return Section::make(__('Angaben des Besuchers'))
            ->description(__('Welche Angaben Besucher dieses Typs am Kiosk und mobil machen.'))
            ->schema($rows);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('host_requirement')
                    ->label(__('Gastgeber'))
                    ->formatStateUsing(fn (string $state): string => VisitorType::hostRequirements()[$state] ?? $state)
                    ->badge(),
                TextColumn::make('sites.name')
                    ->label(__('Standorte'))
                    ->badge()
                    ->default(__('Alle Standorte')),
                IconColumn::make('is_active')->label(__('Aktiv'))->boolean(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([CreateAction::make()])
            ->emptyStateActions([CreateAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVisitorTypes::route('/'),
            'create' => CreateVisitorType::route('/create'),
            'edit' => EditVisitorType::route('/{record}/edit'),
        ];
    }
}
