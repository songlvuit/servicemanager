<?php

namespace Database\Seeders;

use App\Enums\BudgetCategory;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Expenses\ExpenseWorkflow;
use App\Services\Purchasing\PurchaseOrderWorkflow;
use App\Services\Purchasing\PurchaseRequestWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Dữ liệu mẫu Phase 4: 5 nhà cung cấp, 20 đề nghị chi ở đủ các trạng thái,
 * yêu cầu mua hàng, đơn mua hàng và giờ công. Mọi chứng từ đi qua đúng quy
 * trình (workflow + quy trình duyệt) nên chi phí công việc luôn nhất quán: sau
 * khi chạy có công việc "Cảnh báo" và công việc "Vượt ngân sách" để xem thử.
 * Chứng từ gắn vào 8 công việc lớn của DemoContractsSeeder.
 *
 * Chỉ chạy một lần: bỏ qua nếu đã có nhà cung cấp, và không chạy trên production.
 */
class DemoOperationsSeeder extends Seeder
{
    /** @var Collection<string, User> */
    private Collection $users;

    /** @var Collection<int, Task> Các công việc lớn (có hợp đồng), theo thứ tự tạo. */
    private Collection $jobs;

    /** @var list<Supplier> */
    private array $suppliers = [];

    public function run(
        ExpenseWorkflow $expenses,
        PurchaseRequestWorkflow $purchaseRequests,
        PurchaseOrderWorkflow $purchaseOrders,
    ): void {
        if (app()->isProduction()) {
            $this->command?->warn('Bỏ qua DemoOperationsSeeder: không tạo dữ liệu mẫu trên production.');

            return;
        }

        if (Supplier::query()->withTrashed()->exists()) {
            $this->command?->info('Bỏ qua DemoOperationsSeeder: đã có dữ liệu nhà cung cấp.');

            return;
        }

        $this->users = User::query()
            ->whereIn('email', array_map(fn (string $name): string => "{$name}@example.com", ['employee', 'pm', 'accounting', 'director', 'procurement']))
            ->get()
            ->keyBy(fn (User $user): string => strstr($user->email, '@', true));

        $this->jobs = Task::query()->whereNull('parent_id')->whereNotNull('contract_id')->orderBy('id')->get()->values();

        if ($this->users->count() < 5 || $this->jobs->count() < 8) {
            $this->command?->warn('Bỏ qua DemoOperationsSeeder: chưa có tài khoản mẫu hoặc công việc mẫu (chạy các seeder trước đó).');

            return;
        }

        // Đơn giá giờ công để tính chi phí nhân sự từ chấm công.
        $this->users['employee']->update(['hourly_rate' => 120000]);
        $this->users['pm']->update(['hourly_rate' => 200000]);

        $this->seedSuppliers();
        $this->seedExpenses($expenses);
        $this->seedPurchasing($purchaseRequests, $purchaseOrders);
        $this->seedTimesheets();
    }

    private function seedSuppliers(): void
    {
        // [tên, người liên hệ, điện thoại, ngân hàng]
        $rows = [
            ['Công ty TNHH Thiết bị Điện ảnh Sài Gòn', 'Lý Quốc Huy', '02838440011', 'Vietcombank'],
            ['Studio Ánh Sáng', 'Mai Thuỳ Dương', '02839330022', 'Techcombank'],
            ['Công ty Hậu kỳ Phim Việt', 'Tạ Minh Khôi', '02437770033', 'BIDV'],
            ['Công ty Phần mềm Bản Quyền Việt', 'Đinh Hải Yến', '02462660044', 'ACB'],
            ['Công ty In ấn Hoàng Gia', 'Phan Văn Lộc', '02363550055', 'VietinBank'],
        ];

        foreach ($rows as $index => [$name, $contact, $phone, $bank]) {
            $this->suppliers[] = Supplier::create([
                'name' => $name,
                'tax_code' => '03'.str_pad((string) (11220000 + $index * 1111), 8, '0', STR_PAD_LEFT),
                'contact_name' => $contact,
                'phone' => $phone,
                'email' => 'lienhe.ncc'.($index + 1).'@example.com',
                'address' => ($index * 12 + 8).' Nguyễn Văn Trỗi, Phú Nhuận, TP. Hồ Chí Minh',
                'bank_name' => $bank,
                'bank_account' => '00'.str_pad((string) (71000100 + $index * 137), 10, '0', STR_PAD_LEFT),
            ]);
        }
    }

    private function seedExpenses(ExpenseWorkflow $workflow): void
    {
        /*
         * [công việc, nhóm, nội dung, trước thuế, thuế, số ngày trước, nhà cung cấp|null, trạng thái cuối, người đề nghị]
         * Trạng thái cuối: draft, pm (chờ PM), accounting (chờ kế toán), director (chờ giám đốc),
         * approved, rejected, paid, completed.
         */
        $rows = [
            // Việc 1 — TVC: trong ngân sách.
            [0, 'equipment', 'Thuê máy quay và đèn 2 ngày', 8000000, 800000, 6, 0, 'paid', 'employee'],
            [0, 'production', 'Thuê studio 1 ngày', 4500000, 450000, 5, 1, 'paid', 'employee'],
            [0, 'production', 'Đạo cụ và phục trang', 2200000, 0, 3, null, 'approved', 'employee'],
            [0, 'travel', 'Di chuyển đoàn quay', 900000, 0, 2, null, 'pm', 'employee'],
            [0, 'personnel', 'Thù lao diễn viên phụ', 3000000, 0, 2, null, 'accounting', 'employee'],
            // Việc 2 — Video khoá học đợt 1: chi phí vượt 80% ngân sách (cảnh báo).
            [1, 'personnel', 'Thù lao giảng viên đợt 1', 45000000, 0, 30, null, 'completed', 'employee'],
            [1, 'equipment', 'Thuê thiết bị thu âm 10 buổi', 18000000, 1800000, 24, 0, 'paid', 'employee'],
            [1, 'production', 'Dựng phim thuê ngoài 10 bài', 24000000, 2400000, 12, 2, 'paid', 'employee'],
            [1, 'travel', 'Vé máy bay cho giảng viên', 6500000, 0, 20, null, 'paid', 'employee'],
            [1, 'production', 'Làm phụ đề 10 bài giảng', 5000000, 500000, 1, 2, 'director', 'employee'],
            // Việc 5 — Lookbook (đã hoàn thành).
            [4, 'personnel', 'Người mẫu và trang điểm', 12000000, 0, 62, null, 'completed', 'employee'],
            [4, 'equipment', 'Thuê thiết bị chụp ảnh', 5000000, 500000, 60, 0, 'completed', 'employee'],
            [4, 'travel', 'Di chuyển và ăn uống đoàn chụp', 2800000, 0, 58, null, 'completed', 'employee'],
            // Việc 6 — Nhận diện thương hiệu: vượt ngân sách.
            [5, 'personnel', 'Thuê designer tự do', 28000000, 0, 40, null, 'paid', 'employee'],
            [5, 'software', 'Bản quyền font chữ và phần mềm', 5500000, 550000, 33, 3, 'paid', 'employee'],
            [5, 'other', 'In mẫu thử bộ nhận diện', 3200000, 320000, 9, 4, 'paid', 'employee'],
            [5, 'marketing', 'Khảo sát khách hàng mục tiêu', 4000000, 0, 7, null, 'rejected', 'employee'],
            // Các công việc khác.
            [6, 'production', 'Nhạc nền có bản quyền', 1500000, 0, 1, null, 'draft', 'employee'],
            [2, 'equipment', 'Đặt cọc thuê phòng thu', 10000000, 0, 1, 1, 'pm', 'employee'],
            // Do quản lý công việc đề nghị: bước quản lý duyệt được bỏ qua.
            [2, 'personnel', 'Tạm ứng thù lao giảng viên đợt 2', 20000000, 0, 1, null, 'accounting', 'pm'],
        ];

        foreach ($rows as [$jobIndex, $category, $description, $amount, $tax, $daysAgo, $supplierIndex, $final, $requester]) {
            $expense = new Expense([
                'task_id' => $this->jobs[$jobIndex]->getKey(),
                'category' => BudgetCategory::from($category),
                'description' => $description,
                'amount' => $amount,
                'tax' => $tax,
                'expense_date' => today()->subDays($daysAgo),
                'vendor_id' => $supplierIndex === null ? null : $this->suppliers[$supplierIndex]->getKey(),
                'payment_method' => $supplierIndex === null ? PaymentMethod::Cash : PaymentMethod::BankTransfer,
            ]);
            $expense->employee_id = $this->users[$requester]->getKey();
            $expense->save();

            $this->advanceExpense($workflow, $expense, $final, $daysAgo);
        }
    }

    private function advanceExpense(ExpenseWorkflow $workflow, Expense $expense, string $final, int $daysAgo): void
    {
        if ($final === 'draft') {
            return;
        }

        $expense = $workflow->submit($expense, $expense->employee);

        // Lần lượt duyệt qua các cấp cho tới bước cần dừng.
        $approvers = ['pm' => 'pm', 'accounting' => 'accounting', 'director' => 'director'];

        foreach ($approvers as $stage => $approver) {
            if (! $expense->status->isInApproval()) {
                break;
            }

            $awaiting = $expense->currentApproval()->first()?->current_role;

            if ($awaiting !== $this->users[$approver]->getRoleNames()->first()) {
                continue;
            }

            if ($final === $stage) {
                return;
            }

            if ($final === 'rejected') {
                $workflow->reject($expense, $this->users[$approver], 'Khoản chi này chưa có trong kế hoạch của công việc.');

                return;
            }

            $expense = $workflow->approve($expense, $this->users[$approver], 'Đồng ý.');
        }

        if (! in_array($final, ['paid', 'completed'], true)) {
            return;
        }

        // Thanh toán sau ngày phát sinh vài ngày (không muộn hơn hôm nay).
        $expense = $workflow->pay(
            $expense,
            $this->users['accounting'],
            $expense->payment_method ?? PaymentMethod::BankTransfer,
            now()->subDays(max(0, $daysAgo - 2)),
            'UNC-'.str_pad((string) $expense->getKey(), 4, '0', STR_PAD_LEFT),
        );

        if ($final === 'completed') {
            $workflow->complete($expense, $this->users['accounting']);
        }
    }

    private function seedPurchasing(PurchaseRequestWorkflow $requests, PurchaseOrderWorkflow $orders): void
    {
        $procurement = $this->users['procurement'];

        /*
         * [công việc, nhóm, nội dung, giá trị, nhà cung cấp, người yêu cầu, trạng thái yêu cầu, trạng thái đơn]
         * Đơn: null (chưa lập), ordered (đã đặt — chi phí cam kết), received (đã nhận — chi phí thực tế).
         */
        $rows = [
            [1, 'equipment', 'Mua 2 micro không dây cho quay bài giảng', 12000000, 0, 'procurement', 'approved', 'ordered'],
            // Do quản lý công việc yêu cầu: bước quản lý duyệt được bỏ qua, kế toán kiểm tra ngân sách.
            [0, 'production', 'In backdrop và standee cho buổi quay', 3500000, 4, 'pm', 'approved', 'received'],
            [2, 'equipment', 'Thuê màn hình LED cho phòng thu', 25000000, null, 'procurement', 'submitted', null],
        ];

        foreach ($rows as [$jobIndex, $category, $description, $amount, $supplierIndex, $requester, $requestStatus, $orderStatus]) {
            $request = new PurchaseRequest([
                'task_id' => $this->jobs[$jobIndex]->getKey(),
                'supplier_id' => $supplierIndex === null ? null : $this->suppliers[$supplierIndex]->getKey(),
                'category' => BudgetCategory::from($category),
                'description' => $description,
                'amount' => $amount,
                'needed_date' => today()->addDays(10),
            ]);
            $request->requester_id = $this->users[$requester]->getKey();
            $request->save();

            $request = $requests->submit($request, $this->users[$requester]);

            if ($requestStatus !== 'approved') {
                continue;
            }

            // Duyệt lần lượt qua các cấp của quy trình.
            foreach (['pm', 'accounting', 'director'] as $approver) {
                $awaiting = $request->currentApproval()->first()?->current_role;

                if ($awaiting === $this->users[$approver]->getRoleNames()->first()) {
                    $request = $requests->approve($request, $this->users[$approver], 'Cần cho tiến độ công việc.');
                }
            }

            if ($orderStatus === null) {
                continue;
            }

            $order = new PurchaseOrder([
                'purchase_request_id' => $request->getKey(),
                'supplier_id' => $request->supplier_id,
                'task_id' => $request->task_id,
                'category' => $request->category,
                'order_date' => today()->subDays(4),
                'delivery_date' => today()->addDays(6),
            ]);
            $order->created_by = $procurement->getKey();
            $order->save();

            $order->items()->create([
                'description' => $description,
                'quantity' => 1,
                'unit_price' => $amount,
                'tax_rate' => 10,
                'sort_order' => 1,
            ]);

            $order = $orders->order($orders->recalculate($order), $procurement);

            if ($orderStatus === 'received') {
                $orders->receive($order, $procurement);
            }
        }

        // Một đơn nháp lập trực tiếp (không qua yêu cầu mua hàng).
        $draft = new PurchaseOrder([
            'supplier_id' => $this->suppliers[3]->getKey(),
            'task_id' => $this->jobs[5]->getKey(),
            'category' => BudgetCategory::Software,
            'order_date' => today(),
        ]);
        $draft->created_by = $procurement->getKey();
        $draft->save();
        $draft->items()->create(['description' => 'Gia hạn kho ảnh stock 12 tháng', 'quantity' => 1, 'unit_price' => 2000000, 'tax_rate' => 10, 'sort_order' => 1]);
        $orders->recalculate($draft);
    }

    private function seedTimesheets(): void
    {
        // [công việc, người, số ngày trước, số giờ, nội dung]
        $rows = [
            [0, 'employee', 6, 8, 'Quay tại studio — ngày 1'],
            [0, 'employee', 5, 8, 'Quay tại studio — ngày 2'],
            [1, 'employee', 28, 8, 'Quay bài giảng 1–3'],
            [1, 'employee', 27, 8, 'Quay bài giảng 4–6'],
            [1, 'employee', 26, 8, 'Quay bài giảng 7–10'],
            [1, 'employee', 14, 8, 'Dựng thô 5 bài'],
            [1, 'employee', 13, 8, 'Dựng thô 5 bài còn lại'],
            [1, 'pm', 20, 6, 'Duyệt nội dung với giảng viên'],
            [1, 'pm', 10, 4, 'Kiểm tra bản dựng'],
            [4, 'employee', 57, 8, 'Chụp lookbook — ngày 1'],
            [4, 'employee', 56, 8, 'Chụp lookbook — ngày 2'],
            [4, 'employee', 50, 4, 'Chọn và xuất ảnh'],
            [5, 'employee', 30, 8, 'Phác thảo logo'],
            [5, 'employee', 22, 8, 'Hoàn thiện 3 phương án logo'],
        ];

        foreach ($rows as [$jobIndex, $user, $daysAgo, $hours, $description]) {
            $job = $this->jobs[$jobIndex];

            Timesheet::create([
                'user_id' => $this->users[$user]->getKey(),
                // Gắn với một công việc con của người đó, nếu không thì chính công việc lớn.
                'task_id' => $job->children()->where('assignee_id', $this->users[$user]->getKey())->value('id') ?? $job->getKey(),
                'work_date' => today()->subDays($daysAgo),
                'hours' => $hours,
                'description' => $description,
            ]);
        }
    }
}
