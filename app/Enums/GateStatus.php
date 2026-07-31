<?php

namespace App\Enums;

enum GateStatus: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Maintenance = 'maintenance';
    case Closed = 'closed';
}
