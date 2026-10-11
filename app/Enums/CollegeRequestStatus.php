<?php

namespace App\Enums;

enum CollegeRequestStatus: string
{
    case Pending = 'pending';
    case Signed = 'signed';
    case Released = 'released';
}
