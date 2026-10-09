<?php

namespace App\Filament\Resources\ApprovalRules;

use App\Enums\ApprovalDocumentType;
use App\Filament\Resources\ApprovalRules\Pages\ManageApprovalRules;
use App\Filament\Support\ApprovalTimeline;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\VietnameseLabels;
use App\Models\ApprovalRule;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * Cấu hình quy trình duyệt (mục 21, 61): mỗi dòng là một bước duyệt áp dụng
 * cho một loại chứng từ trong một khoảng giá trị.
 */
class ApprovalRuleResource extends Resource
{
    use VietnameseLabels;

    protected static ?string $model = ApprovalRule::class;

    protected static ?string $slug = 'approval-rules';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Hệ thống';

    protected static ?string $navigationLabel = 'Quy trình duyệt';

    protected static ?int $navigationSort = 45;

    protected static ?string $modelLabel = 'bước duyệt';

    protected static ?string $pluralModelLabel = 'quy trình duyệt';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Tên bước')
                    ->placeholder('Ví dụ: Giám đốc duyệt (trên 20 triệu)')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Select::make('document_type')
                    ->label('Áp dụng cho')
                    ->options(ApprovalDocumentType::class)
                    ->required(),
                Select::make('required_role')
                    ->label('Vai trò duyệt')
                    ->options(fn (): array => Role::query()
                        ->orderBy('id')
                        ->pluck('name')
                        ->mapWithKeys(fn (string $name): array => [$name => ApprovalTimeline::roleLabel($name)])
                        ->all())
                    ->required()
                    ->helperText('Ai giữ vai trò này đều duyệt được bước này, trừ chính người đề nghị.'),
                Fields::money('min_amount')
                    ->label('Từ số tiền')
                    ->default(0)
                    ->required(),
                Fields::money('max_amount')
                    ->label('Đến số tiền')
                    ->gte('min_amount')
                    ->helperText('Bỏ trống = không giới hạn.'),
                TextInput::make('sequence')
                    ->label('Thứ tự')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(9999)
                    ->default(10)
                    ->required()
                    ->helperText('Bước có số nhỏ hơn duyệt trước.'),
                Toggle::make('is_active')
                    ->label('Đang áp dụng')
                    ->default(true)
                    ->inline(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sequence')
                    ->label('Thứ tự')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Bước duyệt')
                    ->weight(FontWeight::Medium)
                    ->searchable(),
                TextColumn::make('required_role')
                    ->label('Vai trò duyệt')
                    ->formatStateUsing(fn (string $state): string => ApprovalTimeline::roleLabel($state))
                    ->badge()
                    ->color('gray'),
                Columns::money('min_amount', 'Từ số tiền'),
                Columns::money('max_amount', 'Đến số tiền')
                    ->placeholder('Không giới hạn'),
                IconColumn::make('is_active')
                    ->label('Áp dụng')
                    ->boolean(),
            ])
            ->groups([
                Group::make('document_type')
                    ->label('Loại chứng từ')
                    ->getTitleFromRecordUsing(fn (ApprovalRule $record): string => $record->document_type->getLabel()),
            ])
            ->defaultGroup('document_type')
            ->defaultSort('sequence')
            ->filters([
                SelectFilter::make('document_type')
                    ->label('Loại chứng từ')
                    ->options(ApprovalDocumentType::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->paginated(false)
            ->emptyStateHeading('Chưa có quy trình duyệt nào.')
            ->emptyStateDescription('Khi chưa có bước duyệt, đề nghị chi và yêu cầu mua hàng không gửi duyệt được.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageApprovalRules::route('/'),
        ];
    }
}
