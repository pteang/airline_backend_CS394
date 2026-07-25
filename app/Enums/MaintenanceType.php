<?php

namespace App\Enums;

enum MaintenanceType: string
{
    case Routine = 'routine';
    case Repair = 'repair';
    case Inspection = 'inspection';
    case Overhaul = 'overhaul';
}
