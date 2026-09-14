<?php

namespace App\Enums;

enum LoginActivityType: string
{
    case Login       = 'login';
    case DailyAccess = 'daily_access';
}
