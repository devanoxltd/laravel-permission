<?php

namespace Spatie\Permission\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Guard;

class CreatePermission extends Command
{
    protected $signature = 'permission:create-permission
                {name : The name of the permission}
                {guard? : The name of the guard}';

    protected $description = 'Create a permission';

    public function handle()
    {
        $permissionClass = app(PermissionContract::class);

        $permission = $permissionClass::firstOrCreate(['name' => $this->argument('name'), 'guard_name' => $this->argument('guard') ?? Guard::getDefaultName(config('permission.models.permission'))]);

        $this->info("Permission `{$permission->name}` ".($permission->wasRecentlyCreated ? 'created' : 'already exists'));
    }
}
