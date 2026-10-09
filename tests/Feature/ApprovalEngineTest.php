<?php

namespace Tests\Feature;

use App\Enums\ApprovalDocumentType;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepStatus;
use App\Enums\SystemRole;
use App\Exceptions\WorkflowException;
use App\Filament\Resources\ApprovalRules\ApprovalRuleResource;
use App\Models\ApprovalRule;
use App\Models\Expense;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExpenses;
use Tests\TestCase;

class ApprovalEngineTest extends TestCase
{
    use BuildsExpenses;
    use RefreshDatabase;

    private ApprovalService $approvals;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();

        $this->approvals = app(ApprovalService::class);
        $this->employee = $this->approver(SystemRole::Employee);
    }

    /**
     * Quy trình mặc định của mục 21, đọc từ bảng approval_rules.
     */
    public function test_default_expense_rules_depend_on_the_amount(): void
    {
        $roles = fn (int|string $amount): array => ApprovalRule::query()
            ->matching(ApprovalDocumentType::Expense, $amount)
            ->pluck('required_role')
            ->all();

        // < 5 triệu: PM → Kế toán.
        $this->assertSame(['Project Manager', 'Accountant'], $roles(4999999));
        // 5 – 20 triệu: thêm cấp quản lý.
        $this->assertSame(['Project Manager', 'Accountant', 'Director'], $roles(5000000));
        $this->assertSame(['Project Manager', 'Accountant', 'Director'], $roles(20000000));
        // > 20 triệu: thêm Giám đốc.
        $this->assertSame(['Project Manager', 'Accountant', 'Director'], $roles('20000000.50'));

        $names = ApprovalRule::query()->matching(ApprovalDocumentType::Expense, 20000001)->pluck('name')->all();
        $this->assertContains('Giám đốc duyệt (trên 20 triệu)', $names);
        $this->assertNotContains('Quản lý duyệt (5 – 20 triệu)', $names);
    }

    public function test_rules_are_configurable(): void
    {
        // Tắt bước kế toán, thêm bước cho vai trò tự tạo ở mức từ 1 triệu.
        ApprovalRule::query()->where('required_role', 'Accountant')->update(['is_active' => false]);
        ApprovalRule::create([
            'name' => 'Trưởng phòng duyệt',
            'document_type' => ApprovalDocumentType::Expense,
            'min_amount' => 1000000,
            'required_role' => 'Sales',
            'sequence' => 15,
        ]);

        $request = $this->approvals->start($this->makeExpense(), ApprovalDocumentType::Expense, $this->employee, 2000000);

        $this->assertSame(['Project Manager', 'Sales'], $request->steps->pluck('approver_role')->all());
        $this->assertSame('Project Manager', $request->current_role);
    }

    public function test_starting_without_any_matching_rule_fails(): void
    {
        ApprovalRule::query()->update(['is_active' => false]);

        $this->expectException(WorkflowException::class);

        $this->approvals->start($this->makeExpense(), ApprovalDocumentType::Expense, $this->employee, 1000000);
    }

    public function test_steps_are_approved_in_order_by_the_right_role(): void
    {
        $pm = $this->approver(SystemRole::ProjectManager);
        $accountant = $this->approver(SystemRole::Accountant);

        $request = $this->approvals->start($this->makeExpense(), ApprovalDocumentType::Expense, $this->employee, 1000000);

        $this->assertTrue($this->approvals->canAct($pm, $request));
        $this->assertFalse($this->approvals->canAct($accountant, $request));

        // Kế toán chưa tới lượt.
        try {
            $this->approvals->approve($request, $accountant);
            $this->fail('Kế toán không được duyệt khi đang ở bước của PM.');
        } catch (AuthorizationException) {
            $this->assertSame(ApprovalStatus::Pending, $request->refresh()->status);
        }

        $request = $this->approvals->approve($request, $pm, 'Hợp lý.');

        $this->assertSame('Accountant', $request->current_role);
        $this->assertSame('Hợp lý.', $request->steps->first()->comment);
        $this->assertSame($pm->getKey(), $request->steps->first()->approver_user_id);

        $request = $this->approvals->approve($request, $accountant);

        $this->assertSame(ApprovalStatus::Approved, $request->status);
        $this->assertNull($request->current_role);
        $this->assertNotNull($request->completed_at);
        $this->assertFalse($this->approvals->canAct($accountant, $request));
    }

    public function test_rejecting_a_step_rejects_the_whole_request(): void
    {
        $request = $this->approvals->start($this->makeExpense(), ApprovalDocumentType::Expense, $this->employee, 1000000);

        $request = $this->approvals->reject($request, $this->approver(SystemRole::ProjectManager), 'Không cần thiết.');

        $this->assertSame(ApprovalStatus::Rejected, $request->status);
        $this->assertNull($request->current_role);
        $this->assertSame(ApprovalStepStatus::Rejected, $request->steps->first()->status);
    }

    public function test_requester_cannot_approve_their_own_request(): void
    {
        $pm = $this->approver(SystemRole::ProjectManager);

        // PM tự đề nghị: bước của PM được bỏ qua, chuyển thẳng tới kế toán.
        $request = $this->approvals->start($this->makeExpense(employee: $pm), ApprovalDocumentType::Expense, $pm, 1000000);

        $this->assertSame(
            [ApprovalStepStatus::Skipped, ApprovalStepStatus::Pending],
            $request->steps->pluck('status')->all(),
        );
        $this->assertSame('Accountant', $request->current_role);
        $this->assertFalse($this->approvals->canAct($pm, $request));
    }

    public function test_at_least_one_step_is_approved_by_someone_else(): void
    {
        // Người giữ cả hai vai trò PM và Kế toán tự đề nghị: bước cuối vẫn phải có người khác duyệt.
        $both = $this->createUserWithRole(SystemRole::ProjectManager);
        $both->assignRole(SystemRole::Accountant->value);

        $request = $this->approvals->start($this->makeExpense(employee: $both), ApprovalDocumentType::Expense, $both, 1000000);

        $this->assertSame('Accountant', $request->current_role);
        $this->assertFalse($this->approvals->canAct($both, $request));
        $this->assertTrue($this->approvals->canAct($this->approver(SystemRole::Accountant), $request));
        // Super Admin duyệt được mọi bước.
        $this->assertTrue($this->approvals->canAct($this->approver(SystemRole::SuperAdmin), $request));
    }

    public function test_resubmitting_cancels_the_previous_pending_request(): void
    {
        $expense = $this->makeExpense();

        $first = $this->approvals->start($expense, ApprovalDocumentType::Expense, $this->employee, 1000000);
        $second = $this->approvals->start($expense, ApprovalDocumentType::Expense, $this->employee, 1000000);

        $this->assertSame(ApprovalStatus::Cancelled, $first->refresh()->status);
        $this->assertTrue($second->is($expense->currentApproval()->first()));
        $this->assertSame(1, Expense::query()->awaitingApprovalBy($this->approver(SystemRole::ProjectManager))->count());
    }

    public function test_only_users_with_settings_access_manage_the_rules(): void
    {
        $this->actingAs($this->approver(SystemRole::SuperAdmin))
            ->get(ApprovalRuleResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Giám đốc duyệt (trên 20 triệu)');

        $director = $this->approver(SystemRole::Director);

        // Giám đốc xem được cấu hình nhưng không sửa được.
        $this->actingAs($director)->get(ApprovalRuleResource::getUrl('index'))->assertOk();
        $this->assertFalse($director->can('update', ApprovalRule::query()->first()));

        $this->actingAs($this->employee)
            ->get(ApprovalRuleResource::getUrl('index'))
            ->assertForbidden();
    }
}
