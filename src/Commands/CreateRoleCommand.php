<?php

namespace Spatie\Permission\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\PermissionRegistrar;

class CreateRoleCommand extends Command
{
    protected $signature = 'permission:create-role
        {name : The name of the role}
        {guard? : The name of the guard}
        {permissions? : A list of permissions to assign to the role, separated by | }
        {--team-id=}';

    protected $description = 'Create a role';

    public function handle(PermissionRegistrar $permissionRegistrar): int
    {
        $roleClass = app(RoleContract::class);

        $teamIdAux = getPermissionsTeamId();
        setPermissionsTeamId($this->option('team-id') ?: null);

        if (! $permissionRegistrar->teams && $this->option('team-id')) {
            $this->warn(__('permission::messages.teams_feature_disabled'));

            return self::SUCCESS;
        }

        $role = $roleClass::findOrCreate($this->argument('name'), $this->argument('guard'));
        setPermissionsTeamId($teamIdAux);

        $teams_key = $permissionRegistrar->teamsKey;
        if ($permissionRegistrar->teams && $this->option('team-id') && is_null($role->$teams_key)) {
            $this->warn(__('permission::messages.role_already_exists_global_team', ['role' => $role->name]));
        }

        $role->givePermissionTo($this->makePermissions($this->argument('permissions')));

        $this->info($role->wasRecentlyCreated ? __('permission::messages.role_created', ['role' => $role->name]) : __('permission::messages.role_updated', ['role' => $role->name]));

        return self::SUCCESS;
    }

    protected function makePermissions(?string $string = null): ?Collection
    {
        if (empty($string)) {
            return null;
        }

        $permissionClass = app(PermissionContract::class);

        $permissions = explode('|', $string);

        $models = [];

        foreach ($permissions as $permission) {
            $models[] = $permissionClass::findOrCreate(trim($permission), $this->argument('guard'));
        }

        return collect($models);
    }
}
