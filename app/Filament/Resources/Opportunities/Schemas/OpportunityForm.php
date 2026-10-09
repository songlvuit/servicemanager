<?php

namespace App\Filament\Resources\Opportunities\Schemas;

use App\Enums\OpportunityStatus;
use App\Filament\Resources\Customers\RelationManagers\OpportunitiesRelationManager;
use App\Filament\Support\Fields;
use App\Support\Decimal;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class OpportunityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Cơ hội')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->description('Mã cơ hội (OPP-năm-số thứ tự) được cấp tự động khi lưu.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('customer_id')
                            ->label('Khách hàng')
                            ->relationship(
                                name: 'customer',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->withoutTrashed(),
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            // Trong tab "Cơ hội" của khách hàng thì đã biết khách hàng.
                            ->hiddenOn(OpportunitiesRelationManager::class),
                        TextInput::make('title')
                            ->label('Tên cơ hội')
                            ->required()
                            ->maxLength(255),
                        Select::make('status')
                            ->label('Giai đoạn')
                            ->options(OpportunityStatus::class)
                            ->default(OpportunityStatus::New->value)
                            ->required(),
                        Select::make('sales_owner_id')
                            ->label('Người phụ trách')
                            ->relationship(
                                name: 'salesOwner',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true),
                            )
                            ->default(fn (): ?int => auth()->id())
                            ->searchable()
                            ->preload()
                            ->required(),
                        Textarea::make('description')
                            ->label('Mô tả nhu cầu')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('Giá trị')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        Fields::money('estimated_value')
                            ->label('Giá trị ước tính')
                            ->required()
                            ->default(0)
                            ->live(onBlur: true),
                        Fields::percent('probability')
                            ->label('Xác suất thành công')
                            ->integer()
                            ->required()
                            ->default(50)
                            ->live(onBlur: true),
                        TextEntry::make('expected_revenue_preview')
                            ->label('Doanh thu kỳ vọng')
                            ->helperText('= Giá trị ước tính × Xác suất')
                            ->weight(FontWeight::SemiBold)
                            ->state(fn (Get $get): string => Money::format(
                                Decimal::percentOf($get('estimated_value'), $get('probability')),
                            )),
                        DatePicker::make('expected_close_date')
                            ->label('Dự kiến chốt')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                    ]),
            ]);
    }
}
