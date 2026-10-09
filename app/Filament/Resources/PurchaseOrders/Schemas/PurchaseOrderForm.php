<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Enums\BudgetCategory;
use App\Enums\PurchaseRequestStatus;
use App\Filament\Resources\BudgetItems\Schemas\BudgetItemForm;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Support\Fields;
use App\Models\PurchaseRequest;
use App\Support\Decimal;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
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
use Illuminate\Validation\Rule;

/**
 * Form đơn mua hàng: thông tin chung, các dòng hàng và phần tổng xem trước.
 * Số chính thức được tính lại ở server khi lưu (PurchaseOrderWorkflow::recalculate()).
 * Mở từ một yêu cầu mua hàng đã duyệt (?request=ID) thì dự án, nhà cung cấp,
 * nhóm chi phí và dòng hàng đầu tiên được điền sẵn.
 */
class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin chung')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->description('Số đơn (PO-năm-số thứ tự) được cấp tự động khi lưu.')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('purchase_request_id')
                            ->label('Theo yêu cầu mua hàng')
                            ->options(fn (): array => static::requestOptions())
                            ->default(fn (): ?int => static::sourceRequest()?->getKey())
                            ->rule(Rule::exists('purchase_requests', 'id')->where('status', PurchaseRequestStatus::Approved->value))
                            ->validationMessages(['exists' => 'Chỉ lập được đơn từ yêu cầu mua hàng đã duyệt.'])
                            ->searchable()
                            ->placeholder('Đơn lập trực tiếp (cần quyền Duyệt để đặt hàng)')
                            ->disabledOn('edit')
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                $request = filled($state) ? PurchaseRequest::query()->find($state) : null;

                                if ($request === null) {
                                    return;
                                }

                                $set('project_id', $request->project_id);
                                $set('supplier_id', $request->supplier_id);
                                $set('category', $request->category->value);
                            })
                            ->columnSpanFull(),
                        Select::make('supplier_id')
                            ->label('Nhà cung cấp')
                            ->options(fn (): array => ExpenseForm::supplierOptions())
                            ->default(fn (): ?int => static::sourceRequest()?->supplier_id)
                            ->searchable()
                            ->required(),
                        Select::make('project_id')
                            ->label('Dự án')
                            ->options(fn (): array => BudgetItemForm::projectOptions())
                            ->default(fn (): ?int => static::sourceRequest()?->project_id)
                            ->searchable()
                            ->required(),
                        Select::make('category')
                            ->label('Nhóm chi phí')
                            ->options(BudgetCategory::class)
                            ->default(fn (): string => (static::sourceRequest()?->category ?? BudgetCategory::Equipment)->value)
                            ->required(),
                        DatePicker::make('order_date')
                            ->label('Ngày đặt hàng')
                            ->default(fn (): string => today()->toDateString())
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        DatePicker::make('delivery_date')
                            ->label('Ngày giao hàng dự kiến')
                            ->default(fn (): ?string => static::sourceRequest()?->needed_date?->toDateString())
                            ->afterOrEqual('order_date')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                    ]),

                Section::make('Dòng hàng')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->description('Thành tiền = Số lượng × Đơn giá. Thuế VAT tính trên thành tiền của từng dòng.')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->relationship()
                            ->orderColumn('sort_order')
                            ->table([
                                TableColumn::make('Mô tả')->markAsRequired(),
                                TableColumn::make('Số lượng')->markAsRequired()->width('120px'),
                                TableColumn::make('Đơn giá')->markAsRequired()->width('180px'),
                                TableColumn::make('VAT')->width('110px'),
                            ])
                            ->compact()
                            ->schema([
                                TextInput::make('description')
                                    ->label('Mô tả')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('quantity')
                                    ->label('Số lượng')
                                    ->numeric()
                                    ->required()
                                    ->default(1)
                                    ->minValue(0.01)
                                    ->maxValue(9999999)
                                    ->formatStateUsing(fn (mixed $state): ?string => is_scalar($state) ? Decimal::trimZeros((string) $state) : null)
                                    ->live(onBlur: true),
                                Fields::money('unit_price')
                                    ->label('Đơn giá')
                                    ->required()
                                    ->default(0)
                                    ->live(onBlur: true),
                                Fields::percent('tax_rate')
                                    ->label('Thuế VAT')
                                    ->default((float) config('quotation.default_tax_rate'))
                                    ->live(onBlur: true),
                            ])
                            // Một dòng sẵn có; nếu lập từ yêu cầu mua hàng thì lấy nội dung và giá trị của yêu cầu.
                            ->default(fn (): array => [static::defaultItem()])
                            ->minItems(1)
                            ->addActionLabel('Thêm dòng hàng'),
                    ]),

                Section::make('Tổng hợp')
                    ->icon(Heroicon::OutlinedCalculator)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('summary_subtotal')
                            ->label('Thành tiền trước thuế')
                            ->state(fn (Get $get): string => Money::format(static::totals($get)['subtotal'])),
                        TextEntry::make('summary_tax')
                            ->label('Thuế VAT')
                            ->state(fn (Get $get): string => Money::format(static::totals($get)['tax'])),
                        TextEntry::make('summary_total')
                            ->label('Tổng cộng')
                            ->weight(FontWeight::Bold)
                            ->color('primary')
                            ->state(fn (Get $get): string => Money::format(static::totals($get)['total'])),
                        Textarea::make('notes')
                            ->label('Ghi chú / điều kiện giao hàng')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Tổng xem trước, cùng công thức với PurchaseOrderWorkflow.
     *
     * @return array{subtotal: string, tax: string, total: string}
     */
    private static function totals(Get $get): array
    {
        $subtotal = '0';
        $tax = '0';

        foreach ((array) $get('items') as $item) {
            if (! is_array($item)) {
                continue;
            }

            $line = Decimal::mul(static::number($item['quantity'] ?? null), static::number($item['unit_price'] ?? null));

            $subtotal = Decimal::add($subtotal, $line);
            $tax = Decimal::add($tax, Decimal::percentOf($line, static::number($item['tax_rate'] ?? null)));
        }

        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => Decimal::add($subtotal, $tax)];
    }

    /**
     * Yêu cầu mua hàng nguồn khi form được mở bằng ?request=ID (chỉ nhận yêu cầu đã duyệt).
     */
    private static function sourceRequest(): ?PurchaseRequest
    {
        $id = request()->integer('request');

        if ($id <= 0) {
            return null;
        }

        return PurchaseRequest::query()
            ->whereKey($id)
            ->where('status', PurchaseRequestStatus::Approved->value)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultItem(): array
    {
        $request = static::sourceRequest();

        return [
            'description' => $request === null ? null : (string) str($request->description)->limit(250, ''),
            'quantity' => 1,
            'unit_price' => $request === null ? 0 : Decimal::trimZeros($request->amount),
            'tax_rate' => (float) config('quotation.default_tax_rate'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function requestOptions(): array
    {
        return PurchaseRequest::query()
            ->where('status', PurchaseRequestStatus::Approved->value)
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (PurchaseRequest $request): array => [
                $request->getKey() => "{$request->code} — ".str($request->description)->limit(60),
            ])
            ->all();
    }

    private static function number(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '0';
    }
}
