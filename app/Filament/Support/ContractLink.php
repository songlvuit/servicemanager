<?php

namespace App\Filament\Support;

use App\Enums\ContractStatus;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Contract;
use App\Models\Quotation;
use App\Models\Task;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

/**
 * Liên kết CÔNG VIỆC ↔ HỢP ĐỒNG / BÁO GIÁ trên giao diện.
 *
 * Hợp đồng và báo giá là thông tin kinh doanh: chỉ người có quyền xem Hợp đồng
 * (`contracts.view`) / Báo giá (`quotations.view`) mới thấy — mặc định là Giám
 * đốc và Kế toán. Vì vậy các ô và dòng ở đây KHÔNG được đưa vào form / trang
 * của người không có quyền (không chỉ ẩn đi), để mã hợp đồng không nằm trong
 * dữ liệu gửi xuống trình duyệt của họ.
 */
class ContractLink
{
    public static function canSeeContracts(): bool
    {
        return Access::can('contracts.view');
    }

    public static function canSeeQuotations(): bool
    {
        return Access::can('quotations.view');
    }

    public static function canSee(): bool
    {
        return static::canSeeContracts() || static::canSeeQuotations();
    }

    /**
     * Các ô chọn hợp đồng / báo giá của một công việc; rỗng nếu người dùng
     * không được xem cả hai.
     *
     * @param  bool  $withContract  false khi hợp đồng đã xác định sẵn (tab Công việc của hợp đồng).
     * @return list<Select>
     */
    public static function fields(bool $withContract = true): array
    {
        $fields = [];

        if ($withContract && static::canSeeContracts()) {
            $fields[] = Select::make('contract_id')
                ->label('Hợp đồng')
                ->options(fn (Get $get): array => static::contractOptions($get('contract_id')))
                ->default(fn (): ?int => static::requestedContract()?->getKey())
                ->searchable()
                ->placeholder('Chưa gắn hợp đồng')
                ->helperText('Chi phí của công việc được tính vào chi phí của hợp đồng này.')
                ->live()
                // Chọn hợp đồng thì báo giá và khách hàng đi theo hợp đồng.
                ->afterStateUpdated(function (mixed $state, Set $set): void {
                    $contract = filled($state) ? Contract::query()->find($state) : null;

                    if ($contract !== null) {
                        $set('quotation_id', $contract->quotation_id);
                        $set('customer_id', $contract->customer_id);
                    }
                });
        }

        if (static::canSeeQuotations()) {
            $fields[] = Select::make('quotation_id')
                ->label('Báo giá')
                ->options(fn (Get $get): array => static::quotationOptions($get('quotation_id')))
                ->default(fn (): ?int => static::requestedContract()?->quotation_id ?? static::requestedQuotation()?->getKey())
                ->searchable()
                ->placeholder('Chưa gắn báo giá')
                ->helperText('Gắn hợp đồng thì báo giá tự lấy theo hợp đồng.');
        }

        return $fields;
    }

    /**
     * Phần "Hợp đồng & báo giá" trong form công việc; null nếu người dùng không được xem.
     */
    public static function formSection(bool $withContract = true): ?Section
    {
        $fields = static::fields($withContract);

        if ($fields === []) {
            return null;
        }

        return Section::make('Hợp đồng & báo giá')
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->description('Chỉ người có quyền xem Hợp đồng / Báo giá mới thấy phần này.')
            ->compact()
            ->columns(2)
            ->columnSpanFull()
            ->schema($fields);
    }

    /**
     * Phần "Hợp đồng & báo giá" trên trang công việc; null nếu người dùng không được xem.
     */
    public static function infolistSection(): ?Section
    {
        $entries = [];

        if (static::canSeeContracts()) {
            $entries[] = TextEntry::make('contract_label')
                ->label('Hợp đồng')
                ->state(fn (Task $record): ?string => $record->contract ? $record->contract->code.' — '.$record->contract->title : null)
                ->placeholder('Chưa gắn hợp đồng')
                ->url(fn (Task $record): ?string => $record->contract !== null && ContractResource::canView($record->contract)
                    ? ContractResource::getUrl('view', ['record' => $record->contract])
                    : null);
            $entries[] = Columns::moneyEntry('contract.contract_value', 'Giá trị hợp đồng (trước thuế)')
                ->visible(fn (Task $record): bool => $record->contract !== null);
        }

        if (static::canSeeQuotations()) {
            $entries[] = TextEntry::make('quotation_label')
                ->label('Báo giá')
                ->state(fn (Task $record): ?string => $record->quotation ? $record->quotation->code.' — '.$record->quotation->title : null)
                ->placeholder('Chưa gắn báo giá')
                ->url(fn (Task $record): ?string => $record->quotation !== null && QuotationResource::canView($record->quotation)
                    ? QuotationResource::getUrl('view', ['record' => $record->quotation])
                    : null);
        }

        if ($entries === []) {
            return null;
        }

        return Section::make('Hợp đồng & báo giá')
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->description('Chỉ người có quyền xem Hợp đồng / Báo giá mới thấy phần này.')
            ->columns(['default' => 1, 'lg' => 3])
            ->columnSpanFull()
            ->schema($entries);
    }

    /**
     * Hợp đồng chưa huỷ (kèm hợp đồng đang chọn): "HD-2026-0001 — Tên hợp đồng".
     *
     * @return array<int, string>
     */
    public static function contractOptions(mixed $selected = null): array
    {
        if (! static::canSeeContracts()) {
            return [];
        }

        return Contract::query()
            ->where(function ($query) use ($selected): void {
                $query->where('status', '!=', ContractStatus::Cancelled->value);

                if (filled($selected)) {
                    $query->orWhere('id', $selected);
                }
            })
            ->orderByDesc('id')
            ->limit(300)
            ->get(['id', 'code', 'title'])
            ->mapWithKeys(fn (Contract $contract): array => [$contract->getKey() => $contract->code.' — '.$contract->title])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function quotationOptions(mixed $selected = null): array
    {
        if (! static::canSeeQuotations()) {
            return [];
        }

        return Quotation::query()
            ->orderByDesc('id')
            ->limit(300)
            ->get(['id', 'code', 'title'])
            ->when(filled($selected), fn ($quotations) => $quotations->contains('id', (int) $selected)
                ? $quotations
                : $quotations->concat(Quotation::query()->withTrashed()->whereKey($selected)->get(['id', 'code', 'title'])))
            ->mapWithKeys(fn (Quotation $quotation): array => [$quotation->getKey() => $quotation->code.' — '.$quotation->title])
            ->all();
    }

    /**
     * Hợp đồng truyền qua đường dẫn (?contract=ID) khi tạo công việc từ trang hợp đồng.
     */
    public static function requestedContract(): ?Contract
    {
        $id = request()->integer('contract');

        return $id > 0 && static::canSeeContracts() ? Contract::query()->find($id) : null;
    }

    public static function requestedQuotation(): ?Quotation
    {
        $id = request()->integer('quotation');

        return $id > 0 && static::canSeeQuotations() ? Quotation::query()->find($id) : null;
    }
}
