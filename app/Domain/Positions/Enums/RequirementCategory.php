<?php

namespace App\Domain\Positions\Enums;

enum RequirementCategory: string
{
    case Essential = 'essential';
    case Desirable = 'desirable';
}
