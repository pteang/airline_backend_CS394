<?php

namespace App\Enums;

enum StaffRole: string
{
    case Pilot = 'pilot';
    case Copilot = 'copilot';
    case CabinCrew = 'cabin_crew';
    case Manager = 'manager';
    case Technician = 'technician';
    case GroundStaff = 'ground_staff';
    case Engineer = 'engineer';
}
