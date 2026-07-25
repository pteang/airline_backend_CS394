<?php

namespace App\Enums;

enum StaffAvailabilityStatus: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
    case OnLeave = 'on_leave';
    case Assigned = 'assigned';
}
