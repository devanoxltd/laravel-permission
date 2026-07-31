<?php

namespace Spatie\Permission;

enum PermissionType: string
{
    case All = 'all';
    case Add = 'add';
    case Own = 'own';
    case Both = 'both';
    case None = 'none';
}
