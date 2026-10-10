<?php

namespace App\Filament\Resources\Suppliers;

use App\Enums\SupplierStatus;
use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Support\Columns;
use App\Filament\Support\VietnameseLabels;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class SupplierResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = Supplier::class;

    protected static ?string $slug = 'suppliers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Vận hành';

    protected static ?string $navigationLabel = 'Nhà cung cấp';

    protected static ?int $navigationSort = 60;

    protected static ?string $modelLabel = 'nhà cung cấp';

    protected static ?string $pluralModelLabel = 'nhà cung cấp';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin chung')
                    ->icon(Heroicon::OutlinedTruck)
                    ->description('Mã nhà cung cấp (SUP-năm-số thứ tự) được cấp tự động khi lưu.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Tên nhà cung cấp')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('tax_code')
                            ->label('Mã số thuế')
                            ->maxLength(20),
                        TextInput::make('address')
                            ->label('Địa chỉ')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('status')
                            ->label('Trạng thái')
                            ->options(SupplierStatus::class)
                            ->default(SupplierStatus::Active->value)
                            ->required(),
                    ]),

                Section::make('Liên hệ')
                    ->icon(Heroicon::OutlinedPhone)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('contact_name')
                            ->label('Người liên hệ')
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Điện thoại')
                            ->tel()
                            ->maxLength(30),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                    ]),

                Section::make('Thanh toán')
                    ->icon(Heroicon::OutlinedBuildingLibrary)
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('bank_name')
                            ->label('Ngân hàng')
                            ->maxLength(255),
                        TextInput::make('bank_account')
                            ->label('Số tài khoản')
                            ->maxLength(50),
                        Textarea::make('notes')
                            ->label('Ghi chú')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Mã'),
                TextColumn::make('name')
                    ->label('Nhà cung cấp')
                    ->description(fn (Supplier $record): ?string => $record->contact_name)
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('Điện thoại')
                    ->placeholder('—')
                    ->copyable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('tax_code')
                    ->label('Mã số thuế')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(SupplierStatus::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                RestoreAction::make(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Chưa có nhà cung cấp nào.')
            ->emptyStateDescription('Thêm nhà cung cấp để dùng trong đề nghị chi và đơn mua hàng.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('+ Thêm nhà cung cấp'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code', 'tax_code'];
    }
}
