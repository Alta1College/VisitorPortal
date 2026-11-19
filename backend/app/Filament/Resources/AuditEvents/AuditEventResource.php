<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\AuditEvents;

use App\Audit\AuditEvent;
use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Resources\AuditEvents\Pages\ViewAuditEvent;
use App\Models\Site;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only view of the audit trail. No create, edit or delete pages exist by design.
 */
class AuditEventResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 92;

    protected static ?string $slug = 'audit-events';

    public static function getNavigationLabel(): string
    {
        return __('Audit-Protokoll');
    }

    public static function getModelLabel(): string
    {
        return __('Audit-Eintrag');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Audit-Protokoll');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('occurred_at')->label(__('Zeitpunkt'))->dateTime('d.m.Y H:i:s'),
            TextEntry::make('action')->label(__('Aktion'))->fontFamily('mono'),
            TextEntry::make('result')->label(__('Ergebnis'))->badge()->color(fn (string $state): string => self::resultColor($state)),
            TextEntry::make('channel')->label(__('Kanal')),
            TextEntry::make('actor.name')->label(__('Ausgeführt von'))->default(__('System')),
            TextEntry::make('site.name')->label(__('Standort'))->default('—'),
            TextEntry::make('subject_type')->label(__('Objekttyp'))->default('—'),
            TextEntry::make('subject_id')->label(__('Objekt-ID'))->default('—'),
            TextEntry::make('context')
                ->label(__('Details'))
                ->columnSpanFull()
                ->fontFamily('mono')
                ->state(fn (AuditEvent $record): string => $record->context !== null
                    ? (string) json_encode($record->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : '—')
                ->extraAttributes(['class' => 'whitespace-pre-wrap break-all']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['actor', 'site']))
            ->columns([
                TextColumn::make('occurred_at')->label(__('Zeitpunkt'))->dateTime('d.m.Y H:i:s')->sortable(),
                TextColumn::make('action')->label(__('Aktion'))->fontFamily('mono')->searchable(),
                TextColumn::make('result')->label(__('Ergebnis'))->badge()->color(fn (string $state): string => self::resultColor($state)),
                TextColumn::make('actor.name')->label(__('Ausgeführt von'))->default(__('System')),
                TextColumn::make('site.name')->label(__('Standort'))->default('—')->toggleable(),
                TextColumn::make('subject_type')->label(__('Objekttyp'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('channel')->label(__('Kanal'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label(__('Aktion'))
                    ->options(fn (): array => AuditEvent::query()->distinct()->orderBy('action')->pluck('action', 'action')->all()),
                SelectFilter::make('result')
                    ->label(__('Ergebnis'))
                    ->options([
                        AuditEvent::RESULT_SUCCESS => __('Erfolgreich'),
                        AuditEvent::RESULT_FAILURE => __('Fehlgeschlagen'),
                        AuditEvent::RESULT_DENIED => __('Abgelehnt'),
                    ]),
                SelectFilter::make('site_id')
                    ->label(__('Standort'))
                    ->options(fn (): array => Site::query()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('occurred_at')
                    ->schema([
                        DatePicker::make('from')->label(__('Von')),
                        DatePicker::make('until')->label(__('Bis')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('occurred_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('occurred_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEvents::route('/'),
            'view' => ViewAuditEvent::route('/{record}'),
        ];
    }

    private static function resultColor(string $result): string
    {
        return match ($result) {
            AuditEvent::RESULT_SUCCESS => 'success',
            AuditEvent::RESULT_DENIED => 'warning',
            default => 'danger',
        };
    }
}
