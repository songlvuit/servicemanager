<?php

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Models\Acceptance;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\User;
use App\Services\Billing\AcceptanceWorkflow;
use App\Services\Billing\InvoiceWorkflow;
use App\Services\Billing\PaymentService;
use Illuminate\Database\Seeder;

/**
 * Dữ liệu mẫu Phase 5: 4 biên bản nghiệm thu, 10 hóa đơn ở đủ các trạng thái
 * (nháp, đã phát hành, thu một phần, đã thu đủ, quá hạn ở nhiều nhóm tuổi nợ)
 * và 10 lần thanh toán. Mọi chứng từ đi qua đúng quy trình và PaymentService.
 *
 * Chỉ chạy một lần: bỏ qua nếu đã có hóa đơn, và không chạy trên production.
 */
class DemoBillingSeeder extends Seeder
{
    public function run(AcceptanceWorkflow $acceptances, InvoiceWorkflow $invoices, PaymentService $payments): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Bỏ qua DemoBillingSeeder: không tạo dữ liệu mẫu trên production.');

            return;
        }

        if (Invoice::query()->withTrashed()->exists()) {
            $this->command?->info('Bỏ qua DemoBillingSeeder: đã có dữ liệu hóa đơn.');

            return;
        }

        $users = User::query()
            ->whereIn('email', ['accounting@example.com', 'director@example.com'])
            ->get()
            ->keyBy(fn (User $user): string => strstr($user->email, '@', true));

        // Chứng từ lập theo hợp đồng của từng công việc lớn.
        $jobs = Task::query()->whereNull('parent_id')->whereNotNull('contract_id')->orderBy('id')->get()->values();

        if ($users->count() < 2 || $jobs->count() < 8) {
            $this->command?->warn('Bỏ qua DemoBillingSeeder: chưa có tài khoản mẫu hoặc công việc mẫu (chạy các seeder trước đó).');

            return;
        }

        /*
         * Biên bản nghiệm thu: [khoá, công việc, nội dung, giá trị, số ngày trước, trạng thái cuối]
         */
        $acceptanceRows = [
            ['lookbook', 4, 'Nghiệm thu toàn bộ bộ ảnh lookbook Thu Đông', 46000000, 46, 'approved'],
            ['course', 1, 'Nghiệm thu 10 bài giảng đợt 1', 160000000, 5, 'approved'],
            ['brand', 5, 'Nghiệm thu logo và bộ quy chuẩn nhận diện', 30000000, 1, 'submitted'],
            ['tvc', 0, 'Nghiệm thu bản dựng thô TVC 30 giây', 26000000, 0, 'draft'],
        ];

        $accepted = [];

        foreach ($acceptanceRows as [$key, $jobIndex, $description, $amount, $daysAgo, $final]) {
            $acceptance = new Acceptance([
                'contract_id' => $jobs[$jobIndex]->contract_id,
                'description' => $description,
                'acceptance_date' => today()->subDays($daysAgo),
                'amount' => $amount,
            ]);
            $acceptance->created_by = $users['accounting']->getKey();
            $acceptance->save();

            if ($final !== 'draft') {
                $acceptance = $acceptances->submit($acceptance, $users['accounting']);
            }

            if ($final === 'approved') {
                $acceptance = $acceptances->approve($acceptance, $users['director']);
            }

            $accepted[$key] = $acceptance;
        }

        /*
         * Hóa đơn: [công việc, biên bản|null, nội dung, trước thuế, lập cách đây (ngày), hạn (ngày), phát hành?, thanh toán]
         * Thanh toán: [số tiền, cách đây (ngày)] — 'rest' = thu hết số còn lại.
         */
        $invoiceRows = [
            // Đã thu đủ.
            [4, null, 'Tạm ứng 30% — bộ ảnh lookbook', 13800000, 75, 7, true, [['rest', 70]]],
            [4, 'lookbook', 'Thanh toán 70% còn lại — bộ ảnh lookbook', 32200000, 45, 15, true, [[20000000, 40], ['rest', 28]]],
            [1, null, 'Tạm ứng 30% — video khoá học đợt 1', 48000000, 38, 7, true, [[30000000, 36], ['rest', 33]]],
            // Thu một phần, chưa tới hạn.
            [1, 'course', 'Nghiệm thu đợt 1 — 70% còn lại', 112000000, 4, 15, true, [[30000000, 2], [20000000, 1]]],
            // Quá hạn: 2 ngày, 30 ngày (đã thu một phần), 41 ngày (đã thu một phần), 69 ngày.
            [0, null, 'Tạm ứng 50% khi ký — TVC ra mắt sản phẩm', 26000000, 9, 7, true, []],
            [6, null, 'Tạm ứng 30% — video hậu trường lookbook', 9000000, 44, 14, true, [[2000000, 20]]],
            [5, null, 'Tạm ứng 30% — bộ nhận diện thương hiệu', 18000000, 48, 7, true, [[5000000, 35], [4900000, 30]]],
            [4, null, 'Phí phát sinh chỉnh sửa ảnh', 5000000, 76, 7, true, []],
            // Vừa phát hành, chưa tới hạn.
            [2, null, 'Tạm ứng 30% — video khoá học đợt 2', 48000000, 0, 15, true, []],
            // Nháp.
            [1, null, 'Phí bản quyền nhạc nền phát sinh', 4000000, 0, 15, false, []],
        ];

        foreach ($invoiceRows as [$jobIndex, $acceptanceKey, $description, $subtotal, $daysAgo, $term, $issue, $paymentRows]) {
            $job = $jobs[$jobIndex];
            $date = today()->subDays($daysAgo);

            $invoice = new Invoice([
                'customer_id' => $job->customer_id,
                'contract_id' => $job->contract_id,
                'acceptance_id' => $acceptanceKey === null ? null : $accepted[$acceptanceKey]->getKey(),
                'description' => $description,
                'invoice_date' => $date,
                'due_date' => $date->copy()->addDays($term),
                'subtotal' => $subtotal,
                'tax_rate' => config('billing.default_tax_rate'),
            ]);
            $invoice->created_by = $users['accounting']->getKey();
            $invoice->save();

            if (! $issue) {
                continue;
            }

            $invoice = $invoices->issue($invoice, $users['accounting']);

            foreach ($paymentRows as $index => [$amount, $paidDaysAgo]) {
                $payments->record(
                    $invoice,
                    $users['accounting'],
                    $amount === 'rest' ? $invoice->refresh()->remaining_amount : $amount,
                    $index % 2 === 0 ? PaymentMethod::BankTransfer : PaymentMethod::Cash,
                    today()->subDays($paidDaysAgo),
                    'BC-'.str_pad((string) ($invoice->getKey() * 10 + $index), 5, '0', STR_PAD_LEFT),
                );
            }
        }
    }
}
