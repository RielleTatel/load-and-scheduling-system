<?php

namespace Tests\Unit;

use App\Enums\GradeLevel;
use App\Enums\TimetableStatus;
use App\Models\Department;
use App\Models\Section;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Services\Scheduling\TimetableExportService;
use PHPUnit\Framework\TestCase;

class TimetableExportServiceTest extends TestCase
{
    public function test_pdf_export_is_a_landscape_pdf_and_marks_drafts(): void
    {
        [$timetable, $data] = $this->sample();

        $pdf = (new TimetableExportService)->pdf($timetable, $data);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('/MediaBox [0 0 842 595]', $pdf);
        $this->assertStringContainsString('DRAFT - NOT APPROVED', $pdf);
        $this->assertStringContainsString('TIME', $pdf);
        $this->assertStringContainsString('7 - Ignatius', $pdf);
        $this->assertStringContainsString('RECESS', $pdf);
        $this->assertStringContainsString('LUNCH', $pdf);
        $this->assertStringContainsString('EXAMEN', $pdf);
        $this->assertStringContainsString('CLASSROOM CLEAN-UP \(CC\)', $pdf);
        $this->assertStringContainsString('REMEDIATION \(Science\)', $pdf);
        $this->assertStringContainsString('English', $pdf);
        $this->assertStringContainsString('Music, Arts, Physical', $pdf);
        $this->assertStringContainsString('Education and Health', $pdf);
        $this->assertStringContainsString('Alexandria Catherine Longteacher-Surname', $pdf);
        $this->assertStringNotContainsString('(PERIOD)', $pdf);
        $this->assertStringNotContainsString('(P1)', $pdf);
    }

    public function test_xlsx_export_contains_four_grade_tabs_and_a_valid_zip_directory(): void
    {
        [$timetable, $data] = $this->sample();

        $xlsx = (new TimetableExportService)->xlsx($timetable, $data);

        $this->assertStringStartsWith("PK\x03\x04", $xlsx);
        $files = $this->unzipLocalFiles($xlsx);
        $workbook = $files['xl/workbook.xml'] ?? '';
        $this->assertStringContainsString('Grade 7', $workbook);
        $this->assertStringContainsString('Grade 8', $workbook);
        $this->assertStringContainsString('Grade 9', $workbook);
        $this->assertStringContainsString('Grade 10', $workbook);
        $this->assertTrue(
            strpos($workbook, 'Grade 7') < strpos($workbook, 'Grade 8')
            && strpos($workbook, 'Grade 8') < strpos($workbook, 'Grade 9')
            && strpos($workbook, 'Grade 9') < strpos($workbook, 'Grade 10'),
        );
        $sheet = $files['xl/worksheets/sheet1.xml'] ?? '';
        $this->assertStringContainsString('TIME', $sheet);
        $this->assertStringContainsString('7 - Ignatius', $sheet);
        $this->assertStringContainsString('RECESS', $sheet);
        $this->assertStringContainsString('LUNCH', $sheet);
        $this->assertStringContainsString('EXAMEN', $sheet);
        $this->assertStringContainsString('CLASSROOM CLEAN-UP (CC)', $sheet);
        $this->assertStringContainsString('REMEDIATION (Science)', $sheet);
        $this->assertStringContainsString('English', $sheet);
        $this->assertStringContainsString('Music, Arts, Physical Education and Health', $sheet);
        $this->assertStringContainsString('<mergeCells', $sheet);
        $this->assertStringNotContainsString('Period / time', $sheet);
        $this->assertStringNotContainsString('P1  ', $sheet);
    }

    private function sample(): array
    {
        $timetable = new TimetableVersion([
            'school_year' => '2026-2027',
            'quarter' => 1,
            'version' => 3,
            'status' => TimetableStatus::Draft,
        ]);

        $section = new Section(['grade_level' => GradeLevel::G7, 'name' => 'Ignatius']);
        $section->setAttribute('id', 1);

        $department = new Department(['code' => 'ENG', 'name' => 'English']);
        $teacher = new Teacher(['full_name' => 'Aurestila Jane']);
        $entry = new TimetableEntry(['section_id' => 1, 'day' => 1, 'period' => 1]);
        $entry->setAttribute('id', 10);
        $entry->setRelation('department', $department);
        $entry->setRelation('teacher', $teacher);

        $mapeh = new Department(['code' => 'MAPEH', 'name' => 'MAPEH']);
        $mapehTeacher = new Teacher(['full_name' => 'Alexandria Catherine Longteacher-Surname']);
        $mapehEntry = new TimetableEntry(['section_id' => 1, 'day' => 1, 'period' => 2]);
        $mapehEntry->setAttribute('id', 11);
        $mapehEntry->setRelation('department', $mapeh);
        $mapehEntry->setRelation('teacher', $mapehTeacher);

        return [$timetable, [
            'sections' => collect([$section]),
            'grid' => [1 => [1 => [1 => $entry, 2 => $mapehEntry]]],
            'days' => [1],
            'filters' => [
                'grade' => 'G7', 'grade_only' => true, 'section_id' => null,
                'day' => 1, 'day_only' => true, 'department_id' => null, 'teacher_id' => null,
            ],
        ]];
    }

    /** @return array<string, string> */
    private function unzipLocalFiles(string $zip): array
    {
        $files = [];
        $offset = 0;
        $length = strlen($zip);

        while ($offset + 4 <= $length && substr($zip, $offset, 4) === "PK\x03\x04") {
            $header = unpack('Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnameLength/vextraLength', substr($zip, $offset, 30));
            $nameOffset = $offset + 30;
            $name = substr($zip, $nameOffset, $header['nameLength']);
            $bodyOffset = $nameOffset + $header['nameLength'] + $header['extraLength'];
            $compressed = substr($zip, $bodyOffset, $header['compressed']);
            $files[$name] = gzinflate($compressed) ?: '';
            $offset = $bodyOffset + $header['compressed'];
        }

        return $files;
    }
}
