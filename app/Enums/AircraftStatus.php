<?php

namespace App\Enums;

enum AircraftStatus: string
{
    case Available = 'available';
    case Assigned = 'assigned';
    case Maintenance = 'maintenance';
    case Retired = 'retired';
}
