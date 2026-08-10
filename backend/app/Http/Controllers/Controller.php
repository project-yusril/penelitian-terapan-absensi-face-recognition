<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use App\Traits\ResolvesListQuery;

abstract class Controller
{
    use ApiResponse;
    use ResolvesListQuery;
}
