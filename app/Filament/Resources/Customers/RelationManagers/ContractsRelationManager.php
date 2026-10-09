<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Support\Access;
use App\Filament\Support\Columns;
use App\Models\Contract;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Hợp đồng của khách hàng (chỉ xem; bấm vào dòng để mở hợp đồng).
 */
class ContractsRelationManager extends RelationManager
{
    protected static string $relationship = 'contracts';

    protected static ?string $title = 'Hợp đồng';

    protected static ?string $modelLabel = 'hợp đồng';

    protected static ?string $pluralModelLabel = 'hợp đồng';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedDocumentCheck;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::can('contracts.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                Columns::code(label: 'Số hợp đồng'),
                TextColumn::make('title')
                    ->label('Tên hợp đồng')
                    ->wrap()
                    ->searchable(),
                Columns::money('contract_value', 'Giá trị trước thuế'),
                TextColumn::make('end_date')
                    ->label('Ngày kết thúc')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->recordUrl(fn (Contract $record): string => ContractResource::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Chưa có hợp đồng nào.')
            ->emptyStateDescription('Hợp đồng ký với khách hàng này sẽ hiển thị tại đây.');
    }
}
