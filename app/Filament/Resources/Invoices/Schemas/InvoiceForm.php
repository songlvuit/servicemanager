<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\AcceptanceStatus;
use App\Enums\ContractStatus;
use App\Filament\Support\Fields;
use App\Models\Acceptance;
use App\Models\Contract;
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
 * Form hóa đơn. Mở từ biên bản nghiệm thu (?acceptance=ID) hoặc hợp đồng
 * (?contract=ID) thì các ô liên quan được điền sẵn.
 * Thuế, tổng tiền và số còn phải thu được tính lại ở server khi lưu.
 */
class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin chung')
                    ->icon(Heroicon::OutlinedReceiptPercent)
                    ->description('Số hóa đơn (INV-năm-số thứ tự) được cấp tự động khi lưu.')
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
                            ->default(fn (): ?int => static::source()['customer_id'])
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('contract_id', null);
                                $set('acceptance_id', null);
                            }),
                        Select::make('contract_id')
                            ->label('Hợp đồng')
                            ->options(fn (Get $get): array => static::contractOptions($get('customer_id')))
                            ->default(fn (): ?int => static::source()['contract_id'])
                            ->rule(fn (Get $get): Exists => Rule::exists('contracts', 'id')->where('customer_id', $get('customer_id')))
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('acceptance_id', null))
                            ->helperText('Hợp đồng đang hiệu lực hoặc đã hoàn thành của khách hàng đã chọn.'),
                        Select::make('acceptance_id')
                            ->label('Biên bản nghiệm thu')
                            ->options(fn (Get $get): array => static::acceptanceOptions($get('contract_id')))
                            ->default(fn (): ?int => static::source()['acceptance_id'])
                            ->rule(fn (Get $get): Exists => Rule::exists('acceptances', 'id')
                                ->where('contract_id', $get('contract_id'))
                                ->where('status', AcceptanceStatus::Approved->value))
                            ->searchable()
                            ->placeholder('Không gắn với biên bản nào')
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                $acceptance = filled($state) ? Acceptance::query()->find($state) : null;

                                if ($acceptance === null) {
                                    return;
                                }

                                $set('description', $acceptance->description);
                                $set('subtotal', Decimal::trimZeros($acceptance->amount));
                            }),
                        TextInput::make('description')
                            ->label('Nội dung hóa đơn')
                            ->default(fn (): ?string => static::source()['description'])
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),

                Section::make('Giá trị')
                    ->icon(Heroicon::OutlinedCalculator)
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        Fields::money('subtotal')
                            ->label('Giá trị trước thuế')
                            ->default(fn (): ?string => static::source()['subtotal'])
                            ->required()
                            ->minValue(1)
                            ->live(onBlur: true),
                        Fields::percent('tax_rate')
                            ->label('Thuế suất GTGT')
                            ->default((float) config('billing.default_tax_rate'))
                            ->required()
                            ->live(onBlur: true),
                        TextEntry::make('tax_preview')
                            ->label('Tiền thuế')
                            ->state(fn (Get $get): string => Money::format(static::taxOf($get))),
                        TextEntry::make('total_preview')
                            ->label('Tổng thanh toán')
                            ->weight(FontWeight::Bold)
                            ->color('primary')
                            ->state(fn (Get $get): string => Money::format(Decimal::add(static::number($get('subtotal')), static::taxOf($get)))),
                    ]),

                Section::make('Thời hạn')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        DatePicker::make('invoice_date')
                            ->label('Ngày hóa đơn')
                            ->default(fn (): string => today()->toDateString())
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        DatePicker::make('due_date')
                            ->label('Hạn thanh toán')
                            ->default(fn (): string => today()->addDays((int) config('billing.payment_term_days'))->toDateString())
                            ->required()
                            ->afterOrEqual('invoice_date')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        Textarea::make('notes')
                            ->label('Ghi chú')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Giá trị điền sẵn khi form được mở từ biên bản nghiệm thu hoặc hợp đồng.
     *
     * @return array{customer_id: ?int, contract_id: ?int, acceptance_id: ?int, description: ?string, subtotal: ?string}
     */
    private static function source(): array
    {
        $empty = ['customer_id' => null, 'contract_id' => null, 'acceptance_id' => null, 'description' => null, 'subtotal' => null];

        if (($id = request()->integer('acceptance')) > 0) {
            $acceptance = Acceptance::query()->with('contract')->whereKey($id)->where('status', AcceptanceStatus::Approved->value)->first();
            $contract = $acceptance?->contract;

            return $contract === null ? $empty : [
                'customer_id' => $contract->customer_id,
                'contract_id' => $contract->getKey(),
                'acceptance_id' => $acceptance->getKey(),
                'description' => $acceptance->description,
                'subtotal' => Decimal::trimZeros($acceptance->amount),
            ];
        }

        if (($id = request()->integer('contract')) > 0) {
            $contract = Contract::query()->find($id);

            return $contract === null ? $empty : [
                ...$empty,
                'customer_id' => $contract->customer_id,
                'contract_id' => $contract->getKey(),
            ];
        }

        return [...$empty, 'customer_id' => request()->integer('customer') ?: null];
    }

    /**
     * @return array<int, string>
     */
    private static function contractOptions(mixed $customerId): array
    {
        if (blank($customerId)) {
            return [];
        }

        return Contract::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', [ContractStatus::Active->value, ContractStatus::Completed->value])
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (Contract $contract): array => [$contract->getKey() => "{$contract->code} — {$contract->title}"])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function acceptanceOptions(mixed $contractId): array
    {
        if (blank($contractId)) {
            return [];
        }

        return Acceptance::query()
            ->where('contract_id', $contractId)
            ->where('status', AcceptanceStatus::Approved->value)
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (Acceptance $acceptance): array => [
                $acceptance->getKey() => "{$acceptance->code} — {$acceptance->description} (".Money::format($acceptance->amount).')',
            ])
            ->all();
    }

    private static function taxOf(Get $get): string
    {
        return Decimal::percentOf(static::number($get('subtotal')), static::number($get('tax_rate')));
    }

    private static function number(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '0';
    }
}
