<?php

namespace Tests\Unit;

use App\Services\Plantilla\ExtractionFailedException;
use App\Services\Plantilla\PdfExtractionService;
use Tests\TestCase;

class PdfExtractionServiceTest extends TestCase
{
    public function test_extracts_rows_from_real_filipino_plantilla(): void
    {
        $rows = app(PdfExtractionService::class)->extract(base_path('tests/Fixtures/filipino-plantilla.pdf'));

        $this->assertGreaterThanOrEqual(5, count($rows));

        $names = implode('|', array_column($rows, 'teacher_name'));
        $this->assertStringContainsString('Bilbar', $names);
        $this->assertStringContainsString('Comeros', $names);

        foreach ($rows as $row) {
            $this->assertArrayHasKey('flagged', $row);
            $this->assertArrayHasKey('teacher_name', $row);
        }
    }

    public function test_extracts_employment_status(): void
    {
        $rows = app(PdfExtractionService::class)->extract(base_path('tests/Fixtures/filipino-plantilla.pdf'));
        $statuses = array_filter(array_column($rows, 'employment_status'));

        $this->assertNotEmpty($statuses);
        $this->assertStringContainsStringIgnoringCase('permanent', implode('|', $statuses));
    }

    public function test_textless_pdf_throws(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, '%PDF-1.4 empty');

        $this->expectException(ExtractionFailedException::class);

        try {
            app(PdfExtractionService::class)->extract($path);
        } finally {
            @unlink($path);
        }
    }
}
