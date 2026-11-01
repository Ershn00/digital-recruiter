<?php

namespace App\Domain\Positions\Enums;

enum EmploymentType: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contract = 'contract';
    case Temporary = 'temporary';
    case Internship = 'internship';
}
