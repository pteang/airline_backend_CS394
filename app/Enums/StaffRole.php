<?php

namespace App\Enums;

enum StaffRole: string
{
    case Pilot = 'pilot';
    case CabinCrew = 'cabin_crew';
    case GroundStaff = 'ground_staff';
    case Engineer = 'engineer';
}
