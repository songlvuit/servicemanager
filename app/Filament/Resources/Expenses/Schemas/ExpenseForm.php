<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\BudgetCategory;
use App\Enums\PaymentMethod;
use App\Enums\SupplierStatus;
use App\Filament\Resources\BudgetItems\Schemas\BudgetItemForm;
use App\Filament\Support\Fields;
use App\Filament\Support\TaskField;
use App\Models\Supplier;
use App\Support\Decimal;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

/**
 * Form đề nghị chi, chia theo các phần của mục 44: thông tin chung, tài
 * chính, nhà cung cấp, chứng từ. Phần duyệt nằm ở trang chi tiết.
 */
class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin chung')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->description('Mã đề nghị chi (EXP-năm-số thứ tự) được cấp tự động khi lưu.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('project_id')
                            ->label('Dự án')
                            ->options(fn (): array => BudgetItemForm::projectOptions())
                            // Điền sẵn khi mở từ trang dự án (?project=ID).
                            ->default(fn (): ?int => (request()->integer('project') ?: null) ?? TaskField::requested()?->project_id)
                            ->searchable()
                            ->placeholder('Không gắn dự án')
                            ->helperText('Bỏ trống nếu khoản chi không thuộc dự án nào: khi đó nó là chi phí chung của công ty.')
                            ->live()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('task_id', null)),
                        TaskField::select(),
                        Select::make('category')
                            ->label('Nhóm chi phí')
                            ->options(BudgetCategory::class)
                            ->required()
                            ->helperText('Nếu có gắn dự án, chi phí được tính vào dòng ngân sách cùng nhóm của dự án đó.'),
                        TextInput::make('description')
                            ->label('Nội dung chi')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        DatePicker::make('expense_date')
                            ->label('Ngày phát sinh')
                            ->default(fn (): string => today()->toDateString())
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                    ]),

                Section::make('Tài chính')
                    ->icon(Heroicon::OutlinedCalculator)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Fields::money('amount')
                            ->label('Số tiền trước thuế')
                            ->required()
                            ->minValue(1)
                            ->live(onBlur: true),
                        Fields::money('tax')
                            ->label('Thuế GTGT')
                            ->default(0)
                            ->required()
                            ->live(onBlur: true),
                        TextEntry::make('total_preview')
                            ->label('Tổng số tiền chi')
                            ->weight(FontWeight::SemiBold)
                            ->state(fn (Get $get): string => Money::format(
                                Decimal::add(static::number($get('amount')), static::number($get('tax'))),
                            ))
                            ->helperText('Số tiền này quyết định các cấp duyệt.'),
                    ]),

                Section::make('Nhà cung cấp & thanh toán')
                    ->icon(Heroicon::OutlinedTruck)
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('vendor_id')
                            ->label('Nhà cung cấp')
                            ->options(fn (): array => static::supplierOptions())
                            ->searchable()
                            ->placeholder('Không có'),
                        Select::make('payment_method')
                            ->label('Hình thức thanh toán')
                            ->options(PaymentMethod::class)
                            ->placeholder('Chưa xác định'),
                    ]),

                Section::make('Chứng từ')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->columnSpanFull()
                    ->schema([
                        Text::make('Sau khi lưu, tải hóa đơn / biên nhận / ảnh chứng từ lên ở tab "Tài liệu" của đề nghị chi.'),
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    public static function supplierOptions(): array
    {
        return Supplier::query()
            ->where('status', SupplierStatus::Active->value)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function number(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '0';
    }
}
