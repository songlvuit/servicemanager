<?php

namespace Database\Seeders;

use App\Enums\BudgetCategory;
use App\Enums\QuotationStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Contracts\ContractWorkflow;
use App\Services\Projects\ProjectWorkflow;
use App\Support\Decimal;
use Illuminate\Database\Seeder;

/**
 * Dữ liệu mẫu Phase 3: 5 hợp đồng, 8 dự án (kèm ngân sách, thành viên) và 20
 * công việc. Hợp đồng và dự án được đưa qua đúng quy trình (workflow).
 *
 * Chỉ chạy một lần: bỏ qua nếu đã có hợp đồng, và không chạy trên production.
 */
class DemoProjectsSeeder extends Seeder
{
    public function run(ContractWorkflow $contracts, ProjectWorkflow $projects): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Bỏ qua DemoProjectsSeeder: không tạo dữ liệu mẫu trên production.');

            return;
        }

        if (Contract::query()->withTrashed()->exists()) {
            $this->command?->info('Bỏ qua DemoProjectsSeeder: đã có dữ liệu hợp đồng.');

            return;
        }

        $users = User::query()
            ->whereIn('email', ['sales@example.com', 'director@example.com', 'pm@example.com', 'employee@example.com'])
            ->get()
            ->keyBy(fn (User $user): string => strstr($user->email, '@', true));

        $customers = Customer::query()->orderBy('id')->get()->values();

        if ($users->count() < 4 || $customers->count() < 4) {
            $this->command?->warn('Bỏ qua DemoProjectsSeeder: chưa có tài khoản mẫu hoặc khách hàng mẫu (chạy DevUserSeeder, DemoDataSeeder trước).');

            return;
        }

        $seededContracts = $this->seedContracts($contracts, $customers->all(), $users['sales'], $users['director']);

        $this->seedProjects($projects, $seededContracts, $users['pm'], $users['employee']);
    }

    /**
     * @param  list<Customer>  $customers
     * @return list<Contract>
     */
    private function seedContracts(ContractWorkflow $workflow, array $customers, User $sales, User $director): array
    {
        $accepted = Quotation::query()->where('status', QuotationStatus::Accepted->value)->orderBy('id')->first();

        /*
         * [khách hàng, tiêu đề, giá trị trước thuế, bắt đầu cách đây (ngày), thời hạn (ngày), điều khoản, trạng thái cuối]
         * Dòng đầu lấy theo báo giá đã được khách chấp nhận (nếu có).
         */
        $rows = [
            [0, 'Sản xuất TVC ra mắt sản phẩm', 52000000, 10, 60, 'Tạm ứng 50% khi ký, 50% sau nghiệm thu.', 'active'],
            [1, 'Gói sản xuất video khoá học năm 2026', 480000000, 40, 180, 'Thanh toán theo từng đợt nghiệm thu, trong vòng 15 ngày.', 'active'],
            // Hợp đồng sắp hết hạn (còn 12 ngày).
            [3, 'Bộ ảnh và nhận diện thương hiệu Thu Đông', 136000000, 78, 90, 'Tạm ứng 30%, 70% sau khi bàn giao.', 'active'],
            [2, 'Tổ chức sự kiện mở bán dự án Sông Hàn', 160000000, 0, 45, 'Tạm ứng 40% khi ký, 60% sau sự kiện 7 ngày.', 'pending'],
            [0, 'Gói nội dung mạng xã hội 6 tháng', 135000000, 0, 180, 'Thanh toán hằng tháng.', 'draft'],
        ];

        $contracts = [];

        foreach ($rows as $index => [$customerIndex, $title, $value, $startedDaysAgo, $duration, $terms, $finalStatus]) {
            $fromQuotation = $index === 0 ? $accepted : null;
            $start = today()->subDays($startedDaysAgo);

            $contract = new Contract([
                'customer_id' => $fromQuotation?->customer_id ?? $customers[$customerIndex]->getKey(),
                'quotation_id' => $fromQuotation?->getKey(),
                'title' => $fromQuotation?->title ?? $title,
                'contract_value' => $fromQuotation?->subtotal ?? $value,
                'tax_amount' => $fromQuotation?->tax ?? Decimal::percentOf($value, 10),
                'start_date' => $start,
                'end_date' => $start->copy()->addDays($duration),
                'payment_terms' => $terms,
            ]);
            $contract->created_by = $sales->getKey();
            $contract->save();

            if ($finalStatus !== 'draft') {
                $contract = $workflow->sendForSignature($contract, $sales);
            }

            if ($finalStatus === 'active') {
                $contract = $workflow->activate($contract, $director, $start);
            }

            $contracts[] = $contract;
        }

        return $contracts;
    }

    /**
     * @param  list<Contract>  $contracts
     */
    private function seedProjects(ProjectWorkflow $workflow, array $contracts, User $pm, User $employee): void
    {
        /*
         * [hợp đồng, tên, doanh thu, bắt đầu cách đây (ngày), thời hạn (ngày), trạng thái, ngân sách theo nhóm, công việc]
         * Công việc: [tên, trạng thái, ưu tiên, hạn (ngày tính từ hôm nay), giờ dự kiến, tiến độ]
         */
        $rows = [
            [0, 'TVC 30 giây ra mắt sản phẩm', null, 8, 40, 'active',
                ['personnel' => 12000000, 'equipment' => 9000000, 'production' => 8000000],
                [
                    ['Viết kịch bản và storyboard', 'done', 'high', -3, 16, 100],
                    ['Quay tại studio', 'in_progress', 'urgent', 4, 24, 50],
                    ['Dựng phim và chỉnh màu', 'todo', 'high', 14, 30, 0],
                    ['Thu âm lời bình', 'todo', 'medium', 12, 6, 0],
                ]],
            [1, 'Video khoá học — Đợt 1 (10 bài)', 160000000, 35, 60, 'active',
                ['personnel' => 60000000, 'equipment' => 20000000, 'production' => 25000000, 'travel' => 5000000],
                [
                    ['Chốt đề cương với giảng viên', 'done', 'medium', -25, 8, 100],
                    ['Quay 10 bài giảng', 'done', 'high', -8, 60, 100],
                    ['Dựng và làm phụ đề', 'review', 'high', 2, 80, 90],
                    ['Thiết kế ảnh bìa bài học', 'in_progress', 'low', -2, 12, 40],
                ]],
            [1, 'Video khoá học — Đợt 2 (10 bài)', 160000000, 5, 75, 'planning',
                ['personnel' => 60000000, 'equipment' => 20000000, 'production' => 25000000],
                [
                    ['Lên lịch quay với giảng viên', 'todo', 'medium', 10, 6, 0],
                ]],
            [1, 'Video khoá học — Đợt 3 (10 bài)', 160000000, 0, 120, 'planning', [], []],
            [2, 'Chụp lookbook Thu Đông', 46000000, 75, 30, 'completed',
                ['personnel' => 14000000, 'equipment' => 6000000, 'travel' => 3000000],
                [
                    ['Chọn địa điểm và người mẫu', 'done', 'medium', -65, 10, 100],
                    ['Chụp 2 ngày', 'done', 'high', -55, 20, 100],
                    ['Hậu kỳ 60 ảnh', 'done', 'medium', -48, 30, 100],
                ]],
            [2, 'Thiết kế bộ nhận diện thương hiệu', 60000000, 50, 45, 'active',
                ['personnel' => 30000000, 'software' => 4000000],
                [
                    ['Nghiên cứu thương hiệu', 'done', 'medium', -35, 16, 100],
                    ['Thiết kế logo — 3 phương án', 'review', 'high', -1, 40, 80],
                    ['Bộ quy chuẩn nhận diện', 'in_progress', 'medium', 6, 30, 30],
                    ['Thiết kế bao bì', 'cancelled', 'low', 20, 20, 0],
                ]],
            [2, 'Video hậu trường lookbook', 30000000, 45, 20, 'on_hold',
                ['personnel' => 10000000, 'production' => 6000000],
                [
                    ['Dựng bản nháp 60 giây', 'in_progress', 'low', 15, 12, 20],
                    ['Chọn nhạc nền có bản quyền', 'todo', 'low', 18, 3, 0],
                ]],
            [0, 'Bản cắt 15 giây cho mạng xã hội', 6000000, 3, 20, 'cancelled', [],
                [
                    ['Cắt lại từ bản 30 giây', 'cancelled', 'low', 10, 6, 0],
                    ['Xuất bản dọc 9:16', 'cancelled', 'low', 12, 3, 0],
                ]],
        ];

        foreach ($rows as [$contractIndex, $name, $value, $startedDaysAgo, $duration, $status, $budget, $tasks]) {
            $contract = $contracts[$contractIndex];
            $start = today()->subDays($startedDaysAgo);

            $project = new Project([
                'contract_id' => $contract->getKey(),
                'name' => $name,
                'project_manager_id' => $pm->getKey(),
                'start_date' => $start,
                'end_date' => $start->copy()->addDays($duration),
                // Dự án đầu tiên của một hợp đồng nhỏ dùng luôn giá trị hợp đồng.
                'contract_value' => $value ?? $contract->contract_value,
            ]);
            $project->created_by = $pm->getKey();
            $project->save();

            $project->memberships()->create(['user_id' => $employee->getKey(), 'role' => 'Sản xuất']);

            foreach ($budget as $category => $amount) {
                $project->budgetItems()->create([
                    'category' => BudgetCategory::from($category),
                    'planned_amount' => $amount,
                ]);
            }

            foreach ($tasks as $order => [$taskName, $taskStatus, $priority, $dueInDays, $hours, $progress]) {
                $project->tasks()->create([
                    'name' => $taskName,
                    // Xen kẽ người phụ trách giữa nhân viên và quản lý dự án.
                    'assignee_id' => $order % 2 === 0 ? $employee->getKey() : $pm->getKey(),
                    'status' => TaskStatus::from($taskStatus),
                    'priority' => TaskPriority::from($priority),
                    'start_date' => $start,
                    'due_date' => today()->addDays($dueInDays),
                    'estimated_hours' => $hours,
                    'progress' => $progress,
                ]);
            }

            $this->advance($workflow, $project->refresh(), $status, $pm);
        }
    }

    private function advance(ProjectWorkflow $workflow, Project $project, string $status, User $pm): void
    {
        if ($status === 'planning') {
            return;
        }

        if ($status === 'cancelled') {
            $workflow->cancel($project, $pm);

            return;
        }

        $project = $workflow->start($project, $pm);

        match ($status) {
            'on_hold' => $workflow->hold($project, $pm),
            'completed' => $workflow->complete($project, $pm),
            default => null,
        };
    }
}
