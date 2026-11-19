<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\Policies;

use App\Filament\Resources\Policies\Pages\CreatePolicy;
use App\Filament\Resources\Policies\Pages\EditPolicy;
use App\Filament\Resources\Policies\Pages\ListPolicies;
use App\Filament\Resources\Policies\RelationManagers\VersionsRelationManager;
use App\Models\Site;
use App\VisitorWorkflow\Models\Policy;
use App\VisitorWorkflow\Models\VisitorType;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PolicyResource extends Resource
{
    protected static ?string $model = Policy::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-document-check';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('Besucherablauf');
    }

    public static function getNavigationLabel(): string
    {
        return __('Richtlinien');
    }

    public static function getModelLabel(): string
    {
        return __('Richtlinie');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Richtlinien');
    }

    /**
     * Fields for one policy wording. Used for the first version and for every later version.
     *
     * @return list<Field>
     */
    public static function versionFields(): array
    {
        return [
            TextInput::make('title')->label(__('Titel'))->required()->maxLength(200),
            MarkdownEditor::make('body')
                ->label(__('Text'))
                ->required()
                ->maxLength(50000)
                ->disableToolbarButtons(['attachFiles', 'codeBlock']),
            TextInput::make('acceptance_label')
                ->label(__('Text der Zustimmung'))
                ->required()
                ->maxLength(200)
                ->default(fn (): string => __('Ich habe die Richtlinie gelesen und akzeptiere sie.')),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->label(__('Interner Name'))
                    ->required()
                    ->maxLength(160)
                    ->helperText(__('Für die Verwaltung. Besucher sehen den Titel der jeweiligen Fassung.')),
                TextInput::make('sort_order')->label(__('Reihenfolge'))->integer()->minValue(0)->default(0),
                Toggle::make('is_active')->label(__('Aktiv'))->default(true),
            ]),

            Section::make(__('Erste Fassung'))
                ->description(__('Veröffentlichte Fassungen werden nie geändert. Spätere Änderungen werden als neue Fassung veröffentlicht.'))
                ->statePath('first_version')
                ->visibleOn('create')
                ->schema(self::versionFields()),

            Section::make(__('Gilt für'))
                ->description(__('Leere Auswahl gilt für alle Besuchertypen bzw. alle Standorte. Ohne Zuordnung wird die Richtlinie nirgends abgefragt.'))
                ->schema([
                    Repeater::make('assignments')
                        ->label(__('Zuordnungen'))
                        ->relationship()
                        ->columns(2)
                        ->defaultItems(1)
                        ->schema([
                            Select::make('visitor_type_id')
                                ->label(__('Besuchertyp'))
                                ->options(fn (): array => VisitorType::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                                ->placeholder(__('Alle Besuchertypen'))
                                ->native(false),
                            Select::make('site_id')
                                ->label(__('Standort'))
                                ->options(fn (): array => Site::query()->orderBy('name')->pluck('name', 'id')->all())
                                ->placeholder(__('Alle Standorte'))
                                ->native(false),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->with('currentVersion'))
            ->columns([
                TextColumn::make('name')->label(__('Interner Name'))->searchable(),
                TextColumn::make('currentVersion.version')->label(__('Aktuelle Fassung'))->prefix('v'),
                TextColumn::make('currentVersion.published_at')->label(__('Veröffentlicht'))->dateTime('d.m.Y H:i'),
                TextColumn::make('assignments_count')->label(__('Zuordnungen'))->counts('assignments'),
                IconColumn::make('is_active')->label(__('Aktiv'))->boolean(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([CreateAction::make()])
            ->emptyStateActions([CreateAction::make()]);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPolicies::route('/'),
            'create' => CreatePolicy::route('/create'),
            'edit' => EditPolicy::route('/{record}/edit'),
        ];
    }
}
