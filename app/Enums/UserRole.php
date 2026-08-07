<?php

namespace App\Enums;

enum UserRole: string
{
    case SystemAdmin = 'system_admin';
    case DepartmentChair = 'department_chair';
    case AcademicCoordinator = 'academic_coordinator';

    public function label(): string
    {
        return match ($this) {
            self::SystemAdmin => 'System Administrator',
            self::DepartmentChair => 'Department Chair',
            self::AcademicCoordinator => 'Academic Coordinator',
        };
    }
}
