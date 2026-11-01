<?php

namespace App\Domain\Positions\Enums;

enum PositionStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
