<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\VisitorTypes;

use App\Filament\Resources\VisitorTypes\Pages\CreateVisitorType;
use App\Filament\Resources\VisitorTypes\Pages\EditVisitorType;
use App\Filament\Resources\VisitorTypes\Pages\ListVisitorTypes;
use App\VisitorWorkflow\Models\VisitorType;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
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
                    ->default(VisitorType::HOST_REQUIRED)
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
                TextInput::make('sort_order')->label(__('Reihenfolge'))->integer()->minValue(0)->default(0),
                Toggle::make('is_active')->label(__('Aktiv'))->default(true),
            ]),
        ]);
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
            ->recordActions([EditAction::make()]);
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
