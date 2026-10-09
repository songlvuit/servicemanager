<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Actions\ProjectActions;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Widgets\ProjectStatsOverview;
use App\Models\Project;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Dashboard của dự án (mục 17): hàng thẻ số liệu, cảnh báo ngân sách, bảng
 * kiểm soát chi phí, lãi lỗ và các tab ngân sách, công việc, thành viên, tài liệu.
 */
class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Tổng quan';
    }

    public function getContentTabIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedChartBarSquare;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $project = $this->getRecord();

        return $project->code.' · '.$project->customer?->name;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ProjectStatsOverview::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('board')
                ->label('Bảng Kanban')
                ->icon(Heroicon::OutlinedViewColumns)
                ->color('gray')
                ->url(fn (Project $record): string => ProjectResource::getUrl('board', ['record' => $record])),
            Action::make('openDrive')
                ->label('Mở Google Drive')
                ->icon(Heroicon::OutlinedFolderOpen)
                ->color('gray')
                ->visible(fn (Project $record): bool => str_starts_with((string) $record->drive_url, 'https://'))
                ->url(fn (Project $record): ?string => $record->drive_url, shouldOpenInNewTab: true),
            EditAction::make()
                ->visible(fn (Project $record): bool => ProjectResource::canEdit($record)),
            ProjectActions::start(),
            ProjectActions::resume(),
            ProjectActions::complete(),
            ActionGroup::make([
                ProjectActions::hold(),
                ProjectActions::reopen(),
                ProjectActions::cancel(),
                DeleteAction::make()
                    ->visible(fn (Project $record): bool => ! $record->trashed() && ProjectResource::canDelete($record)),
                RestoreAction::make(),
            ]),
        ];
    }
}
