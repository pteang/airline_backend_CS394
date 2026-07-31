<?php

namespace App\Enums;

enum StaffAvailabilityStatus: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
    case OnLeave = 'on-leave';
    case Training = 'training';
    // Set automatically when a staff member is assigned to a flight's crew.
    case Flight = 'flight';
}
