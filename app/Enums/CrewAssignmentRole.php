<?php

namespace App\Enums;

enum CrewAssignmentRole: string
{
    case Captain = 'captain';
    case FirstOfficer = 'first_officer';
    case Purser = 'purser';
    case FlightAttendant = 'flight_attendant';
    case GroundCrew = 'ground_crew';
}
