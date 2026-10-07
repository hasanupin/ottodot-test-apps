<?php

namespace App\Enums;

enum UserRole: string
{
    case Parent = 'parent';
    case Teacher = 'teacher';
    case Admin = 'admin';
}
