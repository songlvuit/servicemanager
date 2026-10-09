<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\Acceptances\AcceptanceResource;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Models\Acceptance;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab "Nghiệm thu" của dự án (chỉ xem; bấm vào dòng để mở biên bản).
 */
class AcceptancesRelationManager extends RelationManager
{
    protected static string $relationship = 'acceptances';

    protected static ?string $title = 'Nghiệm thu';

    protected static ?string $modelLabel = 'biên bản nghiệm thu';

    protected static ?string $pluralModelLabel = 'biên bản nghiệm thu';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClipboardDocumentCheck;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::can('acceptances.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                Columns::code(label: 'Số biên bản'),
                TextColumn::make('description')
                    ->label('Nội dung nghiệm thu')
                    ->wrap(),
                TextColumn::make('acceptance_date')
                    ->label('Ngày nghiệm thu')
                    ->date()
                    ->sortable(),
                Columns::money('amount', 'Giá trị', summarize: true),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->recordUrl(fn (Acceptance $record): string => AcceptanceResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                Action::make('createAcceptance')
                    ->label('Tạo biên bản nghiệm thu')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->trashed() && AcceptanceResource::canCreate())
                    ->url(fn (): string => AcceptanceResource::getUrl('create', ['project' => $this->getOwnerRecord()->getKey()])),
            ])
            ->defaultSort('acceptance_date', 'desc')
            ->emptyStateHeading('Chưa có biên bản nghiệm thu nào.')
            ->emptyStateDescription('Lập biên bản khi bàn giao một phần hoặc toàn bộ dự án.');
    }
}
