<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Models\Attachment;
use App\Models\Contract;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccessControl();

        Storage::fake(config('uploads.disk'));

        $this->accountant = $this->createUserWithRole(SystemRole::Accountant);
        $this->contract = Contract::factory()->create();
    }

    public function test_user_uploads_a_document_to_a_contract(): void
    {
        $this->actingAs($this->accountant);

        $this->manager()
            ->callAction(TestAction::make('create')->table(), [
                'path' => UploadedFile::fake()->create('hop-dong-da-ky.pdf', 200, 'application/pdf'),
                'category' => 'Hợp đồng',
            ])
            ->assertHasNoFormErrors();

        $attachment = $this->contract->attachments()->first();

        $this->assertNotNull($attachment);
        $this->assertSame('hop-dong-da-ky.pdf', $attachment->filename);
        $this->assertSame('Hợp đồng', $attachment->category);
        $this->assertSame($this->accountant->getKey(), $attachment->uploaded_by);
        $this->assertSame(config('uploads.disk'), $attachment->disk);
        Storage::disk($attachment->disk)->assertExists($attachment->path);
    }

    public function test_disallowed_file_types_and_oversized_files_are_rejected(): void
    {
        $this->actingAs($this->accountant);

        $this->manager()
            ->callAction(TestAction::make('create')->table(), [
                'path' => UploadedFile::fake()->create('ma-doc.exe', 10, 'application/x-msdownload'),
                'category' => 'Tài liệu khác',
            ])
            ->assertHasFormErrors(['path']);

        config(['uploads.max_size_mb' => 1]);

        $this->manager()
            ->callAction(TestAction::make('create')->table(), [
                'path' => UploadedFile::fake()->create('qua-lon.pdf', 2048, 'application/pdf'),
                'category' => 'Tài liệu khác',
            ])
            ->assertHasFormErrors(['path']);

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_upload_button_requires_permission_to_manage_the_document(): void
    {
        // Xem được hợp đồng nhưng không có quyền sửa thì không tải file lên được.
        $this->actingAs($this->createUserWithPermissions(['contracts.view']));

        $this->manager()->assertActionHidden(TestAction::make('create')->table());
    }

    public function test_download_requires_access_to_the_parent_document(): void
    {
        $attachment = $this->storedAttachment();

        $this->get($attachment->url())->assertRedirect('/admin/login');

        $this->actingAs($this->accountant)
            ->get($attachment->url())
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // Nhân viên không có quyền xem hợp đồng.
        $this->actingAs($this->createUserWithRole(SystemRole::Employee))
            ->get($attachment->url())
            ->assertForbidden();

        // Tài khoản đã bị khoá.
        $this->actingAs($this->createUserWithRole(SystemRole::Accountant, ['is_active' => false]))
            ->get($attachment->url())
            ->assertForbidden();
    }

    public function test_deleting_an_attachment_removes_the_file(): void
    {
        $attachment = $this->storedAttachment();

        Storage::disk($attachment->disk)->assertExists($attachment->path);

        $attachment->delete();

        Storage::disk($attachment->disk)->assertMissing($attachment->path);
    }

    private function manager(): Testable
    {
        return Livewire::test(AttachmentsRelationManager::class, [
            'ownerRecord' => $this->contract,
            'pageClass' => ViewContract::class,
        ]);
    }

    private function storedAttachment(): Attachment
    {
        $disk = config('uploads.disk');
        $path = UploadedFile::fake()->create('phu-luc.pdf', 20, 'application/pdf')->store('attachments/test', $disk);

        return $this->contract->attachments()->create([
            'filename' => 'phu-luc.pdf',
            'disk' => $disk,
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 20480,
            'category' => 'Phụ lục',
        ]);
    }
}
