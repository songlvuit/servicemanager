<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Enums\QuotationStatus;
use App\Filament\Support\Fields;
use App\Models\Quotation;
use App\Support\Decimal;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Form hợp đồng. Mở từ báo giá đã được khách chấp nhận (?quotation=ID) thì
 * khách hàng, tiêu đề và giá trị được điền sẵn theo báo giá.
 */
class ContractForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin chung')
                    ->icon(Heroicon::OutlinedDocumentCheck)
                    ->description('Số hợp đồng (HD-năm-số thứ tự) được cấp tự động khi lưu.')
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
                            ->default(fn (): ?int => static::sourceQuotation()?->customer_id ?? (request()->integer('customer') ?: null))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('quotation_id', null)),
                        Select::make('quotation_id')
                            ->label('Báo giá đã được chấp nhận')
                            ->options(fn (Get $get): array => static::quotationOptions($get('customer_id')))
                            ->default(fn (): ?int => static::sourceQuotation()?->getKey())
                            ->rule(fn (Get $get): Exists => Rule::exists('quotations', 'id')
                                ->where('customer_id', $get('customer_id'))
                                ->where('status', QuotationStatus::Accepted->value))
                            ->searchable()
                            ->placeholder('Không gắn với báo giá nào')
                            ->helperText('Chọn báo giá để lấy tiêu đề và giá trị.')
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                $quotation = filled($state) ? Quotation::query()->find($state) : null;

                                if ($quotation === null) {
                                    return;
                                }

                                $set('title', $quotation->title);
                                $set('contract_value', Decimal::trimZeros($quotation->subtotal));
                                $set('tax_amount', Decimal::trimZeros($quotation->tax));
                            }),
                        TextInput::make('title')
                            ->label('Tên hợp đồng')
                            ->default(fn (): ?string => static::sourceQuotation()?->title)
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),

                Section::make('Giá trị hợp đồng')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Fields::money('contract_value')
                            ->label('Giá trị trước thuế')
                            ->default(fn (): ?string => Decimal::trimZeros(static::sourceQuotation()?->subtotal))
                            ->required()
                            ->live(onBlur: true)
                            ->helperText('Doanh thu của hợp đồng, dùng để tính lợi nhuận của hợp đồng.'),
                        Fields::money('tax_amount')
                            ->label('Thuế GTGT')
                            ->default(fn (): string => Decimal::trimZeros(static::sourceQuotation()?->tax) ?? '0')
                            ->required()
                            ->live(onBlur: true),
                        TextEntry::make('total_preview')
                            ->label('Tổng giá trị (gồm thuế)')
                            ->weight(FontWeight::SemiBold)
                            ->state(fn (Get $get): string => Money::format(
                                Decimal::add(static::number($get('contract_value')), static::number($get('tax_amount'))),
                            )),
                    ]),

                Section::make('Thời hạn & điều khoản')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        DatePicker::make('sign_date')
                            ->label('Ngày ký')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        DatePicker::make('start_date')
                            ->label('Ngày bắt đầu')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        DatePicker::make('end_date')
                            ->label('Ngày kết thúc')
                            ->afterOrEqual('start_date')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        Textarea::make('payment_terms')
                            ->label('Điều khoản thanh toán')
                            ->placeholder('Ví dụ: Tạm ứng 50% khi ký, 50% còn lại sau nghiệm thu 7 ngày.')
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label('Ghi chú')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Báo giá nguồn khi form được mở bằng ?quotation=ID (chỉ nhận báo giá đã được chấp nhận).
     */
    private static function sourceQuotation(): ?Quotation
    {
        $id = request()->integer('quotation');

        if ($id <= 0) {
            return null;
        }

        return Quotation::query()
            ->whereKey($id)
            ->where('status', QuotationStatus::Accepted->value)
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private static function quotationOptions(mixed $customerId): array
    {
        if (blank($customerId)) {
            return [];
        }

        return Quotation::query()
            ->where('customer_id', $customerId)
            ->where('status', QuotationStatus::Accepted->value)
            ->orderByDesc('quotation_date')
            ->get()
            ->mapWithKeys(fn (Quotation $quotation): array => [
                $quotation->getKey() => "{$quotation->code} — {$quotation->title}",
            ])
            ->all();
    }

    private static function number(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '0';
    }
}
