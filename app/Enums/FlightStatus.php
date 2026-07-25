<?php

namespace App\Enums;

enum FlightStatus: string
{
    case Scheduled = 'scheduled';
    case Boarding = 'boarding';
    case Departed = 'departed';
    case InAir = 'in_air';
    case Landed = 'landed';
    case Arrived = 'arrived';
    case Delayed = 'delayed';
    case Cancelled = 'cancelled';
}
