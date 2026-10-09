<?php

namespace App\Filament\Resources\Quotations\Schemas;

use App\Filament\Resources\Services\Schemas\ServiceForm;
use App\Filament\Support\Access;
use App\Filament\Support\Fields;
use App\Models\Opportunity;
use App\Models\Service;
use App\Services\Quotations\QuotationCalculator;
use App\Services\Quotations\QuotationTotals;
use App\Support\Decimal;
use App\Support\Money;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Form báo giá: thông tin chung, bảng hạng mục và phần tổng hợp xem trước.
 *
 * Các con số trong phần "Tổng hợp" chỉ để người nhập xem trước; số liệu chính
 * thức được tính lại ở server khi lưu (QuotationWorkflow::recalculate()).
 */
class QuotationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                static::generalSection(),
                static::itemsSection(),
                static::summarySection(),
            ]);
    }

    private static function generalSection(): Section
    {
        return Section::make('Thông tin chung')
            ->icon(Heroicon::OutlinedDocumentText)
            ->description('Số báo giá (QT-năm-số thứ tự) được cấp tự động khi lưu.')
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
                    // Điền sẵn khi mở từ trang khách hàng / cơ hội (?customer=..&opportunity=..).
                    ->default(fn (): ?int => request()->integer('customer') ?: null)
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set): mixed => $set('opportunity_id', null)),
                Select::make('opportunity_id')
                    ->label('Cơ hội liên quan')
                    ->options(fn (Get $get): array => static::opportunityOptions($get('customer_id')))
                    ->default(fn (): ?int => request()->integer('opportunity') ?: null)
                    ->rule(fn (Get $get): Exists => Rule::exists('opportunities', 'id')->where('customer_id', $get('customer_id')))
                    ->searchable()
                    ->placeholder('Không gắn với cơ hội nào')
                    ->helperText('Chỉ liệt kê cơ hội của khách hàng đã chọn.'),
                TextInput::make('title')
                    ->label('Tiêu đề báo giá')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                DatePicker::make('quotation_date')
                    ->label('Ngày báo giá')
                    ->default(fn (): string => today()->toDateString())
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y'),
                DatePicker::make('valid_until')
                    ->label('Hiệu lực đến')
                    ->default(fn (): string => today()->addDays((int) config('quotation.validity_days'))->toDateString())
                    ->required()
                    ->afterOrEqual('quotation_date')
                    ->native(false)
                    ->displayFormat('d/m/Y'),
                Textarea::make('description')
                    ->label('Mô tả / điều khoản')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    private static function itemsSection(): Section
    {
        $canSeeCosts = Access::canSeeCosts();

        // Cập nhật ô "Thành tiền" của dòng khi số lượng / đơn giá / chiết khấu đổi.
        $refreshLine = function (Get $get, Set $set): void {
            $set('subtotal', Decimal::trimZeros(app(QuotationCalculator::class)->lineSubtotal([
                'quantity' => $get('quantity'),
                'unit_price' => $get('unit_price'),
                'discount' => $get('discount'),
            ])));
        };

        $columns = [
            TableColumn::make('Dịch vụ')->width('190px'),
            TableColumn::make('Mô tả')->markAsRequired(),
            TableColumn::make('SL')->markAsRequired()->width('90px'),
            TableColumn::make('ĐVT')->width('100px'),
            TableColumn::make('Đơn giá')->markAsRequired()->width('160px'),
            TableColumn::make('Chiết khấu')->width('150px'),
            TableColumn::make('VAT')->width('100px'),
        ];

        $fields = [
            Select::make('service_id')
                ->label('Dịch vụ')
                ->options(fn (): array => Service::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->placeholder('Tự nhập')
                ->live()
                // Chọn dịch vụ thì điền sẵn mô tả, đơn vị, đơn giá (và giá vốn) từ danh mục.
                ->afterStateUpdated(function (mixed $state, Get $get, Set $set) use ($canSeeCosts, $refreshLine): void {
                    $service = filled($state) ? Service::query()->find($state) : null;

                    if ($service === null) {
                        return;
                    }

                    $set('description', $service->name);
                    $set('unit', $service->default_unit);
                    $set('unit_price', Decimal::trimZeros($service->default_price));

                    if ($canSeeCosts) {
                        $set('estimated_cost', Decimal::trimZeros($service->default_cost));
                    }

                    $refreshLine($get, $set);
                }),
            TextInput::make('description')
                ->label('Mô tả')
                ->required()
                ->maxLength(500),
            TextInput::make('quantity')
                ->label('Số lượng')
                ->numeric()
                ->required()
                ->default(1)
                ->minValue(0.01)
                ->maxValue(9999999)
                ->formatStateUsing(fn (mixed $state): ?string => is_scalar($state) ? Decimal::trimZeros((string) $state) : null)
                ->live(onBlur: true)
                ->afterStateUpdated($refreshLine),
            TextInput::make('unit')
                ->label('Đơn vị tính')
                ->maxLength(50)
                ->datalist(ServiceForm::UNIT_SUGGESTIONS),
            Fields::money('unit_price')
                ->label('Đơn giá')
                ->required()
                ->default(0)
                ->rule(fn (Get $get): Closure => static::lineWithinLimitRule($get))
                ->live(onBlur: true)
                ->afterStateUpdated($refreshLine),
            Fields::money('discount')
                ->label('Chiết khấu')
                ->default(0)
                ->rule(fn (Get $get): Closure => static::discountWithinLineRule($get))
                ->live(onBlur: true)
                ->afterStateUpdated($refreshLine),
            Fields::percent('tax_rate')
                ->label('Thuế VAT')
                ->default((float) config('quotation.default_tax_rate'))
                ->live(onBlur: true),
        ];

        // Giá vốn là thông tin nội bộ: người không có quyền thì không có cột này,
        // hệ thống tự lấy giá vốn mặc định của dịch vụ khi lưu.
        if ($canSeeCosts) {
            $columns[] = TableColumn::make('Giá vốn / ĐV')->width('160px');
            $fields[] = Fields::money('estimated_cost')
                ->label('Giá vốn / đơn vị')
                ->default(0)
                ->live(onBlur: true);
        }

        $columns[] = TableColumn::make('Thành tiền')->width('170px');
        $fields[] = Fields::money('subtotal')
            ->label('Thành tiền')
            ->default(0)
            ->disabled()
            ->dehydrated(false);

        return Section::make('Hạng mục báo giá')
            ->icon(Heroicon::OutlinedListBullet)
            ->description('Thành tiền = Số lượng × Đơn giá − Chiết khấu. Thuế VAT tính trên thành tiền của từng hạng mục.')
            ->columnSpanFull()
            ->schema([
                Repeater::make('items')
                    ->hiddenLabel()
                    ->relationship()
                    ->orderColumn('sort_order')
                    ->table($columns)
                    ->compact()
                    ->schema($fields)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel('Thêm hạng mục'),
            ]);
    }

    private static function summarySection(): Section
    {
        $canSeeCosts = Access::canSeeCosts();

        $money = fn (string $property): Closure => fn (Get $get): string => Money::format(static::totals($get)->{$property});

        $entries = [
            TextEntry::make('summary_gross')
                ->label('Tổng trước chiết khấu')
                ->state($money('gross')),
            TextEntry::make('summary_discount')
                ->label('Chiết khấu')
                ->state(function (Get $get): string {
                    $totals = static::totals($get);

                    return Money::format($totals->discount).' ('.Money::percent($totals->discountPercent).')';
                }),
            TextEntry::make('summary_subtotal')
                ->label('Thành tiền trước thuế')
                ->state($money('subtotal')),
            TextEntry::make('summary_tax')
                ->label('Thuế VAT')
                ->state($money('tax')),
            TextEntry::make('summary_total')
                ->label('Tổng cộng')
                ->weight(FontWeight::Bold)
                ->size(TextSize::Large)
                ->color('primary')
                ->state($money('total')),
        ];

        if ($canSeeCosts) {
            $entries[] = TextEntry::make('summary_cost')
                ->label('Giá vốn ước tính')
                ->state($money('estimatedCost'));
            $entries[] = TextEntry::make('summary_profit')
                ->label('Lợi nhuận ước tính')
                ->state($money('estimatedProfit'));
            $entries[] = TextEntry::make('summary_margin')
                ->label('Biên lợi nhuận')
                ->weight(FontWeight::Bold)
                ->color(fn (Get $get): string => static::isLowMargin($get) ? 'danger' : 'success')
                ->state(fn (Get $get): string => Money::percent(static::totals($get)->estimatedMargin));
        }

        return Section::make('Tổng hợp')
            ->icon(Heroicon::OutlinedCalculator)
            ->columns(4)
            ->columnSpanFull()
            ->schema([
                ...$entries,
                Callout::make('Biên lợi nhuận thấp.')
                    ->description('Biên lợi nhuận ước tính thấp hơn '.Money::percent(config('quotation.low_margin_threshold')).'. Người duyệt sẽ được cảnh báo.')
                    ->warning()
                    ->visible(fn (Get $get): bool => static::hasAmounts($get) && static::isLowMargin($get))
                    ->columnSpanFull(),
                Callout::make('Cần Giám đốc duyệt.')
                    ->description('Chiết khấu vượt '.Money::percent(config('quotation.director_discount_threshold')).' giá trị báo giá nên phải do Giám đốc duyệt.')
                    ->warning()
                    ->visible(fn (Get $get): bool => static::totals($get)->exceedsDiscount(config('quotation.director_discount_threshold')))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function opportunityOptions(mixed $customerId): array
    {
        if (blank($customerId)) {
            return [];
        }

        return Opportunity::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (Opportunity $opportunity): array => [
                $opportunity->getKey() => "{$opportunity->code} — {$opportunity->title}",
            ])
            ->all();
    }

    /**
     * Chiết khấu của một hạng mục không được vượt quá Số lượng × Đơn giá.
     */
    private static function discountWithinLineRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $gross = Decimal::mul($get('quantity'), $get('unit_price'));

            if (is_scalar($value) && Decimal::compare((string) $value, $gross) > 0) {
                $fail('Chiết khấu không được lớn hơn Số lượng × Đơn giá của hạng mục.');
            }
        };
    }

    /**
     * Số lượng × Đơn giá phải nằm trong giới hạn của cột tiền DECIMAL(15,2).
     */
    private static function lineWithinLimitRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if (! is_scalar($value)) {
                return;
            }

            if (Decimal::compare(Decimal::mul($get('quantity'), (string) $value), Fields::MAX_MONEY) > 0) {
                $fail('Số lượng × Đơn giá vượt quá giá trị tối đa cho phép.');
            }
        };
    }

    private static function totals(Get $get): QuotationTotals
    {
        $items = $get('items');

        return app(QuotationCalculator::class)->calculate(is_array($items) ? $items : []);
    }

    private static function hasAmounts(Get $get): bool
    {
        return Decimal::isPositive(static::totals($get)->subtotal);
    }

    private static function isLowMargin(Get $get): bool
    {
        return static::totals($get)->isLowMargin(config('quotation.low_margin_threshold'));
    }
}
