<?php

namespace App\Filament\Resources\ApprovalRules\Pages;

use App\Filament\Resources\ApprovalRules\ApprovalRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageApprovalRules extends ManageRecords
{
    protected static string $resource = ApprovalRuleResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Quy trình của một chứng từ gồm mọi bước đang áp dụng có khoảng giá trị chứa số tiền của chứng từ, duyệt theo thứ tự. '
            .'Bước thuộc vai trò mà chính người đề nghị đang giữ sẽ được bỏ qua.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm bước duyệt'),
        ];
    }
}
