<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\ContractStatus;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Support\Fields;
use App\Models\Contract;
use App\Support\Decimal;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;

/**
 * Form dự án. Dự án chỉ tạo được từ hợp đồng đang hiệu lực (Rule 1); khách
 * hàng lấy theo hợp đồng.
 */
class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin chung')
                    ->icon(Heroicon::OutlinedBriefcase)
                    ->description('Mã dự án (PRJ-năm-số thứ tự) được cấp tự động khi lưu.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('contract_id')
                            ->label('Hợp đồng')
                            ->options(fn (): array => static::contractOptions())
                            ->default(fn (): ?int => static::sourceContract()?->getKey())
                            ->rule(Rule::exists('contracts', 'id')->where('status', ContractStatus::Active->value))
                            ->validationMessages(['exists' => 'Chỉ tạo được dự án từ hợp đồng đang hiệu lực.'])
                            ->searchable()
                            ->required()
                            ->disabledOn('edit')
                            ->helperText('Chỉ liệt kê hợp đồng đang hiệu lực.')
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                $contract = filled($state) ? Contract::query()->find($state) : null;

                                if ($contract === null) {
                                    return;
                                }

                                $set('contract_value', Decimal::trimZeros($contract->contract_value));

                                if (blank($get('name'))) {
                                    $set('name', $contract->title);
                                }
                            }),
                        Select::make('project_manager_id')
                            ->label('Quản lý dự án')
                            ->options(fn (): array => TaskForm::userOptions())
                            ->default(fn (): ?int => auth()->id())
                            ->searchable(),
                        TextInput::make('name')
                            ->label('Tên dự án')
                            ->default(fn (): ?string => static::sourceContract()?->title)
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->label('Mô tả')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('Thời gian & giá trị')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        DatePicker::make('start_date')
                            ->label('Ngày bắt đầu')
                            ->default(fn (): string => today()->toDateString())
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        DatePicker::make('end_date')
                            ->label('Hạn hoàn thành')
                            ->afterOrEqual('start_date')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        Fields::money('contract_value')
                            ->label('Doanh thu của dự án (trước thuế)')
                            ->default(fn (): ?string => Decimal::trimZeros(static::sourceContract()?->contract_value))
                            ->required()
                            ->helperText('Mặc định bằng giá trị hợp đồng; sửa lại nếu hợp đồng chia thành nhiều dự án.'),
                    ]),

                Section::make('Tài liệu')
                    ->icon(Heroicon::OutlinedFolderOpen)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('drive_url')
                            ->label('Thư mục Google Drive')
                            ->placeholder('https://drive.google.com/...')
                            ->url()
                            ->rule('url:https')
                            ->maxLength(500),
                    ]),
            ]);
    }

    /**
     * Hợp đồng nguồn khi form được mở bằng ?contract=ID (chỉ nhận hợp đồng đang hiệu lực).
     */
    private static function sourceContract(): ?Contract
    {
        $id = request()->integer('contract');

        if ($id <= 0) {
            return null;
        }

        return Contract::query()
            ->whereKey($id)
            ->where('status', ContractStatus::Active->value)
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private static function contractOptions(): array
    {
        return Contract::query()
            ->with('customer')
            ->where('status', ContractStatus::Active->value)
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (Contract $contract): array => [
                $contract->getKey() => "{$contract->code} — {$contract->title} ({$contract->customer?->name})",
            ])
            ->all();
    }
}
