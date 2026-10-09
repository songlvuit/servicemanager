<?php

namespace App\Filament\Pages;

use App\Filament\Support\Access;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use UnitEnum;

/**
 * Hệ thống → Cấu hình: thông tin công ty và các ngưỡng nghiệp vụ (thuế mặc
 * định, ngưỡng cảnh báo ngân sách, biên lợi nhuận, số ngày nhắc hạn…). Giá
 * trị lưu ở đây ghi đè giá trị mặc định trong config/*.php.
 *
 * @property-read Schema $form
 */
class SystemSettings extends Page
{
    protected static ?string $slug = 'settings';

    protected static ?string $title = 'Cấu hình';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Hệ thống';

    protected static ?int $navigationSort = 50;

    protected ?string $subheading = 'Thông tin công ty và các ngưỡng nghiệp vụ. Thay đổi có hiệu lực ngay và được ghi vào Audit Log.';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Access::can('settings.view');
    }

    public static function canEdit(): bool
    {
        return Access::can('settings.update');
    }

    public function mount(): void
    {
        $this->form->fill(Arr::undot(Settings::current()));
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];

        foreach (Settings::groups() as $group => $definitions) {
            $fields = [];

            foreach ($definitions as $key => $definition) {
                $fields[] = $this->field($key, $definition);
            }

            $sections[] = Section::make($group)
                ->columns(2)
                ->schema($fields);
        }

        return $schema
            ->statePath('data')
            ->disabled(! static::canEdit())
            ->components($sections);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Lưu cấu hình')
                            ->submit('save')
                            ->keyBindings(['mod+s'])
                            ->visible(static::canEdit()),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canEdit(), 403);

        $values = Arr::only(Arr::dot($this->form->getState()), array_keys(Settings::definitions()));

        if ($error = $this->marginError($values)) {
            Notification::make()->title('Chưa lưu được cấu hình')->body($error)->danger()->send();

            return;
        }

        Settings::save($values);

        Notification::make()->title('Đã lưu cấu hình')->success()->send();
    }

    /**
     * @param  array{label: string, type: string, help?: string, min?: int, max?: int}  $definition
     */
    private function field(string $key, array $definition): TextInput
    {
        $field = TextInput::make($key)
            ->label($definition['label'])
            ->helperText($definition['help'] ?? null);

        return match ($definition['type']) {
            Settings::TYPE_INTEGER => $field
                ->required()
                ->integer()
                ->minValue($definition['min'] ?? 0)
                ->maxValue($definition['max'] ?? 100000),
            Settings::TYPE_PERCENT => $field
                ->required()
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%'),
            default => $field->maxLength(255),
        };
    }

    /**
     * Ba ngưỡng biên lợi nhuận phải tăng dần: nguy hiểm < cảnh báo < tốt.
     *
     * @param  array<string, mixed>  $values
     */
    private function marginError(array $values): ?string
    {
        $danger = (float) ($values['project.margin_danger'] ?? 0);
        $warning = (float) ($values['project.margin_warning'] ?? 0);
        $good = (float) ($values['project.margin_good'] ?? 0);

        return $danger < $warning && $warning <= $good
            ? null
            : 'Ngưỡng biên lợi nhuận phải tăng dần: "nguy hiểm" nhỏ hơn "cảnh báo", và "cảnh báo" không lớn hơn "tốt".';
    }
}
