<?php

namespace Tests\Unit;

use App\Enums\EmploymentStatus;
use PHPUnit\Framework\TestCase;

class EmploymentStatusTest extends TestCase
{
    public function test_from_label_canonicalizes_source_variants(): void
    {
        $this->assertSame(EmploymentStatus::Permanent, EmploymentStatus::fromLabel('FT Permanent'));
        $this->assertSame(EmploymentStatus::Permanent, EmploymentStatus::fromLabel('Permanent Teacher'));
        $this->assertSame(EmploymentStatus::Probationary1, EmploymentStatus::fromLabel('New Teacher'));
        $this->assertSame(EmploymentStatus::Probationary2, EmploymentStatus::fromLabel('FT Probationary 2'));
        $this->assertSame(EmploymentStatus::Substitute, EmploymentStatus::fromLabel('Substitute (Probationary 1)'));
        $this->assertSame(EmploymentStatus::Retiree, EmploymentStatus::fromLabel('Retiree'));
        $this->assertNull(EmploymentStatus::fromLabel('garbage'));
        $this->assertNull(EmploymentStatus::fromLabel(''));
    }
}
