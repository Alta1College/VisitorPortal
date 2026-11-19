<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\Policies\RelationManagers;

use App\Filament\Resources\Policies\PolicyResource;
use App\VisitorWorkflow\Models\Policy;
use App\VisitorWorkflow\Models\PolicyVersion;
use App\VisitorWorkflow\PolicyPublisher;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * Read-only list of published wordings, plus "publish new version". There is deliberately no
 * edit or delete: acceptances point at these rows.
 */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('Fassungen');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('version')->label(__('Fassung'))->prefix('v'),
                TextColumn::make('title')->label(__('Titel')),
                TextColumn::make('published_at')->label(__('Veröffentlicht'))->dateTime('d.m.Y H:i'),
                TextColumn::make('content_hash')->label(__('Prüfsumme'))->limit(12)->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('publish')
                    ->label(__('Neue Fassung veröffentlichen'))
                    ->icon('heroicon-o-document-plus')
                    ->modalDescription(__('Die bisherige Fassung bleibt unverändert gespeichert. Neue Besucher müssen die neue Fassung akzeptieren.'))
                    ->fillForm(function (): array {
                        $current = $this->ownerRecord->currentVersion;

                        return $current ? $current->only(['title', 'body', 'acceptance_label']) : [];
                    })
                    ->schema(PolicyResource::versionFields())
                    ->action(function (array $data): void {
                        /** @var Policy $policy */
                        $policy = $this->ownerRecord;

                        try {
                            $version = app(PolicyPublisher::class)->publish($policy, $data['title'], $data['body'], $data['acceptance_label'], auth()->user());
                        } catch (InvalidArgumentException) {
                            Notification::make()->warning()->title(__('Der Text ist unverändert. Es wurde keine neue Fassung erstellt.'))->send();

                            return;
                        }

                        Notification::make()->success()->title(__('Fassung v:version veröffentlicht.', ['version' => $version->version]))->send();
                    }),
            ])
            ->recordActions([
                Action::make('show')
                    ->label(__('Anzeigen'))
                    ->icon('heroicon-o-eye')
                    ->modalSubmitAction(false)
                    ->modalHeading(fn (PolicyVersion $record): string => $record->title.' (v'.$record->version.')')
                    ->schema(fn (PolicyVersion $record): array => [
                        TextEntry::make('body')->hiddenLabel()->state($record->renderedBody())->html(),
                        TextEntry::make('acceptance_label')->label(__('Text der Zustimmung'))->state($record->acceptance_label),
                    ]),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
