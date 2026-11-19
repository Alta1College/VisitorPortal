<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\FormFields;

use App\Filament\Resources\FormFields\Pages\CreateFormField;
use App\Filament\Resources\FormFields\Pages\EditFormField;
use App\Filament\Resources\FormFields\Pages\ListFormFields;
use App\Models\Site;
use App\VisitorWorkflow\ConditionEvaluator;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\VisitorType;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FormFieldResource extends Resource
{
    protected static ?string $model = FormField::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('Besucherablauf');
    }

    public static function getNavigationLabel(): string
    {
        return __('Fragen und Felder');
    }

    public static function getModelLabel(): string
    {
        return __('Feld');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Fragen und Felder');
    }

    public static function form(Schema $schema): Schema
    {
        $is = fn (string ...$types) => fn (Get $get): bool => in_array($get('type'), $types, true);

        return $schema->components([
            Section::make(__('Feld'))->columns(2)->schema([
                TextInput::make('label')->label(__('Frage / Beschriftung'))->required()->maxLength(200)->columnSpanFull(),
                TextInput::make('key')
                    ->label(__('Kennung'))
                    ->required()
                    ->maxLength(80)
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->unique(ignoreRecord: true)
                    ->disabledOn('edit')
                    ->helperText(__('Kleinbuchstaben, Ziffern und Unterstriche. Wird in Bedingungen verwendet und kann später nicht geändert werden.')),
                Select::make('type')
                    ->label(__('Feldtyp'))
                    ->options(FormField::types())
                    ->required()
                    ->live()
                    ->native(false),
                Textarea::make('help_text')->label(__('Hilfetext'))->rows(2)->columnSpanFull(),
                Repeater::make('options')
                    ->label(__('Auswahlmöglichkeiten'))
                    ->visible($is(FormField::TYPE_DROPDOWN))
                    ->required($is(FormField::TYPE_DROPDOWN))
                    ->minItems(1)
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('value')->label(__('Wert'))->required()->maxLength(80),
                        TextInput::make('label')->label(__('Anzeigetext'))->required()->maxLength(200),
                    ]),
                TextInput::make('validation.max_length')
                    ->label(__('Maximale Länge'))
                    ->integer()->minValue(1)->maxValue(5000)
                    ->visible($is(FormField::TYPE_SHORT_TEXT, FormField::TYPE_LONG_TEXT)),
                TextInput::make('validation.min')
                    ->label(__('Mindestwert'))
                    ->visible($is(FormField::TYPE_NUMBER, FormField::TYPE_DATE))
                    ->helperText(fn (Get $get): ?string => $get('type') === FormField::TYPE_DATE ? __('Datum (JJJJ-MM-TT) oder „today“, z. B. für Ablaufdaten.') : null)
                    ->rule(fn (Get $get) => $get('type') === FormField::TYPE_DATE ? 'regex:/^(today|\d{4}-\d{2}-\d{2})$/' : 'numeric'),
                TextInput::make('validation.max')
                    ->label(__('Höchstwert'))
                    ->visible($is(FormField::TYPE_NUMBER, FormField::TYPE_DATE))
                    ->rule(fn (Get $get) => $get('type') === FormField::TYPE_DATE ? 'regex:/^(today|\d{4}-\d{2}-\d{2})$/' : 'numeric'),
                TextInput::make('validation.max_kb')
                    ->label(__('Maximale Dateigröße (KB)'))
                    ->integer()->minValue(50)->maxValue(20480)
                    ->visible($is(...FormField::FILE_TYPES)),
            ]),

            Section::make(__('Verwendung'))->columns(2)->schema([
                Toggle::make('is_active')->label(__('Aktiv'))->default(true),
                Toggle::make('is_sensitive')
                    ->label(__('Sensibel'))
                    ->helperText(__('Antworten werden verschlüsselt gespeichert und nie auf Ausweisen oder Anzeigen gezeigt.'))
                    ->live(),
                Toggle::make('show_on_badge')->label(__('Auf dem Ausweis zeigen'))->disabled(fn (Get $get): bool => (bool) $get('is_sensitive')),
                Toggle::make('show_on_roll_call')->label(__('In der Anwesenheitsliste zeigen'))->helperText(__('Z. B. als Sicherheitshinweis bei einer Evakuierung.')),
                Toggle::make('remember_for_returning')
                    ->label(__('Für wiederkehrende Gäste merken'))
                    ->helperText(__('Die letzte Antwort wird beim nächsten Besuch vorausgefüllt (z. B. WWCC-Nummer, Foto). Aus lassen bei Antworten, die sich jedes Mal ändern, z. B. Besuchsgrund.'))
                    ->disabled(fn (Get $get): bool => ! in_array($get('type'), FormField::REMEMBERABLE_TYPES, true)),
            ]),

            Section::make(__('Bedingung'))
                ->description(__('Optional: Feld nur anzeigen, wenn frühere Antworten passen. Ein ausgeblendetes Feld ist nie Pflicht.'))
                ->collapsible()
                ->schema([
                    Select::make('conditions.match')
                        ->label(__('Anzeigen, wenn'))
                        ->options(['all' => __('alle Regeln zutreffen'), 'any' => __('mindestens eine Regel zutrifft')])
                        ->default('all')
                        ->native(false),
                    Repeater::make('conditions.rules')
                        ->label(__('Regeln'))
                        ->columns(3)
                        ->defaultItems(0)
                        ->schema([
                            Select::make('field')
                                ->label(__('Feld'))
                                ->options(fn (?FormField $record): array => FormField::query()
                                    ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                                    ->orderBy('label')->pluck('label', 'key')->all())
                                ->required()
                                ->native(false),
                            Select::make('operator')
                                ->label(__('Bedingung'))
                                ->options(array_combine(ConditionEvaluator::OPERATORS, [
                                    __('ist gleich'), __('ist nicht gleich'), __('ist einer von'), __('ist ausgefüllt'), __('ist leer'),
                                ]))
                                ->required()
                                ->native(false),
                            TextInput::make('value')
                                ->label(__('Wert'))
                                ->helperText(__('Ja/Nein: 1 oder 0. Mehrere Werte durch Komma trennen.')),
                        ]),
                ]),

            Section::make(__('Wo wird das Feld abgefragt?'))
                ->description(__('Leere Auswahl gilt für alle Besuchertypen bzw. alle Standorte. Die genaueste Zuordnung gewinnt.'))
                ->schema([
                    Repeater::make('assignments')
                        ->label(__('Zuordnungen'))
                        ->relationship()
                        ->columns(4)
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
                            TextInput::make('sort_order')->label(__('Reihenfolge'))->integer()->minValue(0)->default(0),
                            Toggle::make('is_required')->label(__('Pflichtfeld'))->inline(false),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('label')
            ->columns([
                TextColumn::make('label')->label(__('Frage / Beschriftung'))->searchable()->wrap(),
                TextColumn::make('key')->label(__('Kennung'))->fontFamily('mono')->toggleable(),
                TextColumn::make('type')->label(__('Feldtyp'))->formatStateUsing(fn (string $state): string => FormField::types()[$state] ?? $state)->badge(),
                IconColumn::make('is_sensitive')->label(__('Sensibel'))->boolean(),
                TextColumn::make('assignments_count')->label(__('Zuordnungen'))->counts('assignments'),
                IconColumn::make('is_active')->label(__('Aktiv'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Feldtyp'))->options(FormField::types()),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([CreateAction::make()])
            ->emptyStateActions([CreateAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFormFields::route('/'),
            'create' => CreateFormField::route('/create'),
            'edit' => EditFormField::route('/{record}/edit'),
        ];
    }
}
