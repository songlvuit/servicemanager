<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Enums\ContractStatus;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\Tables\TasksTable;
use App\Filament\Support\Access;
use App\Filament\Support\TaskField;
use App\Models\Task;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab "Công việc" của hợp đồng: các công việc thực hiện hợp đồng. Chi phí của
 * chúng là chi phí của hợp đồng. Gắn thêm công việc có sẵn hoặc tạo mới ngay ở đây.
 */
class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Công việc';

    protected static ?string $modelLabel = 'công việc';

    protected static ?string $pluralModelLabel = 'công việc';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedRectangleStack;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::can('tasks.view');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(TaskForm::components(withContract: false));
    }

    public function table(Table $table): Table
    {
        return TasksTable::configure($table, withContract: false)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['assignee', 'parent', 'customer'])->visibleTo(auth()->user()))
            ->headerActions([
                $this->attachTasksAction(),
                CreateAction::make()
                    ->label('Thêm công việc')
                    ->visible(fn (): bool => $this->isOpen() && (auth()->user()?->can('create', Task::class) ?? false)),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data): array => TaskForm::state($data)),
                Action::make('detach')
                    ->label('Gỡ khỏi hợp đồng')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Task $record): string => 'Gỡ "'.$record->name.'" khỏi hợp đồng?')
                    ->modalDescription('Công việc vẫn còn nguyên, chỉ không tính chi phí vào hợp đồng này nữa.')
                    ->visible(fn (Task $record): bool => $record->parent_id === null && Access::can('contracts.update'))
                    ->action(function (Task $record): void {
                        $record->linkToContract(['contract_id' => null]);

                        Notification::make()->title('Đã gỡ công việc khỏi hợp đồng.')->success()->send();
                    }),
                DeleteAction::make(),
            ]);
    }

    /**
     * Gắn vào hợp đồng những công việc gốc chưa thuộc hợp đồng nào.
     */
    public function attachTasksAction(): Action
    {
        return Action::make('attachTasks')
            ->label('Gắn công việc có sẵn')
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->visible(fn (): bool => $this->isOpen() && Access::can('contracts.update'))
            ->modalHeading('Gắn công việc vào hợp đồng')
            ->modalDescription('Chi phí của công việc (và các công việc con của nó) sẽ tính vào hợp đồng này.')
            ->modalSubmitActionLabel('Gắn')
            ->modalWidth(Width::Large)
            ->schema([
                Select::make('tasks')
                    ->label('Công việc')
                    ->options(fn (): array => Task::query()
                        ->whereNull('contract_id')
                        ->whereNull('parent_id')
                        ->where('status', '!=', TaskStatus::Cancelled->value)
                        ->orderByDesc('id')
                        ->limit(300)
                        ->get(['id', 'name', 'start_date'])
                        ->mapWithKeys(fn (Task $task): array => [$task->getKey() => TaskField::label($task)])
                        ->all())
                    ->multiple()
                    ->searchable()
                    ->required()
                    ->helperText('Chỉ liệt kê công việc chưa gắn hợp đồng nào.'),
            ])
            ->action(function (array $data): void {
                $contract = $this->getOwnerRecord();
                $tasks = Task::query()->whereKey($data['tasks'] ?? [])->whereNull('contract_id')->whereNull('parent_id')->get();

                foreach ($tasks as $task) {
                    $task->linkToContract(['contract_id' => $contract->getKey()]);
                }

                Notification::make()->title('Đã gắn '.$tasks->count().' công việc vào hợp đồng.')->success()->send();
            });
    }

    /**
     * Hợp đồng đã huỷ hoặc đã xoá thì không nhận thêm công việc.
     */
    private function isOpen(): bool
    {
        $contract = $this->getOwnerRecord();

        return ! $contract->trashed() && $contract->status !== ContractStatus::Cancelled;
    }
}
