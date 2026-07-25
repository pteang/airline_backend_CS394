<?php

namespace App\Enums;

enum TripType: string
{
    case OneWay = 'one_way';
    case RoundTrip = 'round_trip';
}
