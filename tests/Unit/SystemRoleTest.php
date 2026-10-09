<?php

namespace Tests\Unit;

use App\Enums\SystemRole;
use PHPUnit\Framework\TestCase;

class SystemRoleTest extends TestCase
{
    public function test_it_defines_the_seven_system_roles(): void
    {
        $this->assertSame(
            ['Super Admin', 'Director', 'Sales', 'Project Manager', 'Accountant', 'Procurement', 'Employee'],
            array_column(SystemRole::cases(), 'value'),
        );
    }

    public function test_it_recognises_system_roles(): void
    {
        $this->assertTrue(SystemRole::isSystem('Director'));
        $this->assertFalse(SystemRole::isSystem('Thực tập sinh'));
        $this->assertFalse(SystemRole::isSystem(null));
    }

    public function test_it_labels_roles_in_vietnamese_and_keeps_custom_names(): void
    {
        $this->assertSame('Giám đốc', SystemRole::labelFor('Director'));
        $this->assertSame('Thực tập sinh', SystemRole::labelFor('Thực tập sinh'));
    }
}
