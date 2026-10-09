<?php

namespace Tests\Feature;

use App\Services\Sequences\DocumentSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentSequenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_codes_increase_per_prefix_and_year(): void
    {
        $this->travelTo('2026-03-15 10:00:00');

        $sequence = app(DocumentSequence::class);

        $this->assertSame('CUS-2026-0001', $sequence->next('CUS'));
        $this->assertSame('CUS-2026-0002', $sequence->next('CUS'));
        $this->assertSame('QT-2026-0001', $sequence->next('QT'));
        $this->assertSame('CUS-2026-0003', $sequence->next('CUS'));
    }

    public function test_numbering_restarts_each_year(): void
    {
        $sequence = app(DocumentSequence::class);

        $this->travelTo('2026-12-31 23:00:00');
        $this->assertSame('QT-2026-0001', $sequence->next('QT'));

        $this->travelTo('2027-01-01 08:00:00');
        $this->assertSame('QT-2027-0001', $sequence->next('QT'));
    }

    public function test_non_yearly_codes_have_no_year_part(): void
    {
        $sequence = app(DocumentSequence::class);

        $this->assertSame('SV-0001', $sequence->next('SV', yearly: false));
        $this->assertSame('SV-0002', $sequence->next('SV', yearly: false));
    }
}
