<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\SiteQrLinks;

use App\Audit\AuditLogger;
use App\Filament\Resources\SiteQrLinks\Pages\ListSiteQrLinks;
use App\Models\Site;
use App\QrCheckIn\SiteQrLink;
use App\QrCheckIn\SiteQrLinks;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Mobile check-in QR codes, one per site: show, print as a poster, turn off, or replace.
 */
class SiteQrLinkResource extends Resource
{
    protected static ?string $model = SiteQrLink::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-qr-code';

    protected static ?int $navigationSort = 45;

    public static function getNavigationGroup(): ?string
    {
        return __('Besucherablauf');
    }

    public static function getNavigationLabel(): string
    {
        return __('QR-Anmeldung');
    }

    public static function getModelLabel(): string
    {
        return __('QR-Code');
    }

    public static function getPluralModelLabel(): string
    {
        return __('QR-Codes');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('site'))
            ->columns([
                TextColumn::make('site.name')->label(__('Standort'))->searchable(),
                ToggleColumn::make('is_enabled')
                    ->label(__('Aktiv'))
                    ->afterStateUpdated(fn (SiteQrLink $record, bool $state) => app(AuditLogger::class)
                        ->record($state ? 'qr_link.enabled' : 'qr_link.disabled', subject: $record, site: $record->site_id)),
                TextColumn::make('rotated_at')->label(__('Zuletzt ersetzt'))->since()->placeholder(__('Nie')),
            ])
            ->recordActions([
                self::showAction(),
                Action::make('poster')
                    ->label(__('Aushang drucken'))
                    ->icon('heroicon-o-printer')
                    ->url(fn (SiteQrLink $record): string => route('qr-check-in.poster', $record))
                    ->openUrlInNewTab(),
                Action::make('rotate')
                    ->label(__('Ersetzen'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('Der bisherige QR-Code und gedruckte Aushänge funktionieren danach nicht mehr. Kiosks zeigen sofort den neuen Code.'))
                    ->action(fn (SiteQrLink $record) => app(SiteQrLinks::class)->rotate($record))
                    ->successNotificationTitle(__('Neuer QR-Code erstellt. Bitte Aushänge neu drucken.')),
            ])
            ->emptyStateActions([self::addAction()])
            ->emptyStateDescription(__('Richten Sie für einen Standort einen QR-Code ein. Besucher scannen ihn und melden sich auf ihrem Handy an.'));
    }

    public static function addAction(): Action
    {
        return Action::make('add')
            ->label(__('QR-Code für Standort einrichten'))
            ->icon('heroicon-o-plus')
            ->authorize('create')
            ->schema([
                Select::make('site_id')
                    ->label(__('Standort'))
                    ->options(fn (): array => Site::query()->where('is_active', true)
                        ->whereNotIn('id', SiteQrLink::query()->select('site_id'))
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data): void {
                $link = app(SiteQrLinks::class)->forSite(Site::query()->findOrFail($data['site_id']));
                app(AuditLogger::class)->record('qr_link.created', subject: $link, site: $link->site_id);
            })
            ->successNotificationTitle(__('QR-Code eingerichtet.'));
    }

    private static function showAction(): Action
    {
        return Action::make('show')
            ->label(__('Anzeigen'))
            ->icon('heroicon-o-qr-code')
            ->modalHeading(fn (SiteQrLink $record): string => __('QR-Code: :site', ['site' => $record->site->name]))
            ->modalContent(function (SiteQrLink $record): HtmlString {
                $links = app(SiteQrLinks::class);
                $url = $links->url($record);

                return new HtmlString(
                    '<div style="display:flex;flex-direction:column;align-items:center;gap:1rem">'
                    .'<div style="width:16rem;height:16rem">'.$links->svg($url, 256).'</div>'
                    .'<code style="word-break:break-all;font-size:.8rem">'.e($url).'</code>'
                    .($record->is_enabled ? '' : '<p style="color:#b91c1c">'.e(__('Ausgeschaltet: der Link funktioniert gerade nicht.')).'</p>')
                    .'</div>'
                );
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Schließen'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSiteQrLinks::route('/'),
        ];
    }
}
