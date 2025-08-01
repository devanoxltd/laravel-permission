<?php

namespace Spatie\Permission\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Contracts\Role;
use Spatie\Permission\Contracts\Wildcard;
use Spatie\Permission\Events\PermissionAttached;
use Spatie\Permission\Events\PermissionDetached;
use Spatie\Permission\Exceptions\GuardDoesNotMatch;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\WildcardPermissionInvalidArgument;
use Spatie\Permission\Exceptions\WildcardPermissionNotImplementsContract;
use Spatie\Permission\Guard;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\WildcardPermission;

trait HasPermissions
{
    private ?string $permissionClass = null;

    private ?string $wildcardClass = null;

    private array $wildcardPermissionsIndex;

    public static function bootHasPermissions()
    {
        static::deleting(function ($model) {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $teams = app(PermissionRegistrar::class)->teams;
            app(PermissionRegistrar::class)->teams = false;
            if (! is_a($model, Permission::class)) {
                $model->permissions()->detach();
            }
            if (is_a($model, Role::class)) {
                $model->users()->detach();
            }
            app(PermissionRegistrar::class)->teams = $teams;
        });
    }

    public function getPermissionClass(): string
    {
        if (! $this->permissionClass) {
            $this->permissionClass = app(PermissionRegistrar::class)->getPermissionClass();
        }

        return $this->permissionClass;
    }

    public function getWildcardClass()
    {
        if (! is_null($this->wildcardClass)) {
            return $this->wildcardClass;
        }

        $this->wildcardClass = '';

        if (config('permission.enable_wildcard_permission')) {
            $this->wildcardClass = config('permission.wildcard_permission', WildcardPermission::class);

            if (! is_subclass_of($this->wildcardClass, Wildcard::class)) {
                throw WildcardPermissionNotImplementsContract::create();
            }
        }

        return $this->wildcardClass;
    }

    /**
     * A model may have multiple direct permissions.
     */
    public function permissions(): BelongsToMany
    {
        $relation = $this->morphToMany(
            config('permission.models.permission'),
            'model',
            config('permission.table_names.model_has_permissions'),
            config('permission.column_names.model_morph_key'),
            app(PermissionRegistrar::class)->pivotPermission
        );

        if (! app(PermissionRegistrar::class)->teams) {
            return $relation->withPivot('permission_type');
        }

        $teamsKey = app(PermissionRegistrar::class)->teamsKey;
        $relation->withPivot($teamsKey, 'permission_type');

        return $relation->wherePivot($teamsKey, getPermissionsTeamId());
    }

    /**
     * Scope the model query to certain permissions only.
     *
     * @param  string|int|array|Permission|Collection|\BackedEnum  $permissions
     * @param  bool  $without
     */
    public function scopePermission(Builder $query, $permissions, $without = false): Builder
    {
        $permissions = $this->convertToPermissionModels($permissions);

        $permissionKey = (new ($this->getPermissionClass())())->getKeyName();
        $roleKey = (new (is_a($this, Role::class) ? static::class : $this->getRoleClass())())->getKeyName();

        $rolesWithPermissions = is_a($this, Role::class) ? [] : array_unique(
            array_reduce($permissions, fn ($result, $permission) => array_merge($result, $permission->roles->all()), [])
        );

        return $query->where(fn (Builder $query) => $query
            ->{! $without ? 'whereHas' : 'whereDoesntHave'}('permissions', fn (Builder $subQuery) => $subQuery
            ->whereIn(config('permission.table_names.permissions').".$permissionKey", \array_column($permissions, $permissionKey))
            )
            ->when(count($rolesWithPermissions), fn ($whenQuery) => $whenQuery
                ->{! $without ? 'orWhereHas' : 'whereDoesntHave'}('roles', fn (Builder $subQuery) => $subQuery
                ->whereIn(config('permission.table_names.roles').".$roleKey", \array_column($rolesWithPermissions, $roleKey))
                )
            )
        );
    }

    /**
     * Scope the model query to only those without certain permissions,
     * whether indirectly by role or by direct permission.
     *
     * @param  string|int|array|Permission|Collection|\BackedEnum  $permissions
     */
    public function scopeWithoutPermission(Builder $query, $permissions): Builder
    {
        return $this->scopePermission($query, $permissions, true);
    }

    /**
     * @param  string|int|array|Permission|Collection|\BackedEnum  $permissions
     *
     * @throws PermissionDoesNotExist
     */
    protected function convertToPermissionModels($permissions): array
    {
        if ($permissions instanceof Collection) {
            $permissions = $permissions->all();
        }

        return array_map(function ($permission) {
            if ($permission instanceof Permission) {
                return $permission;
            }

            if ($permission instanceof \BackedEnum) {
                $permission = $permission->value;
            }

            $method = is_int($permission) || PermissionRegistrar::isUid($permission) ? 'findById' : 'findByName';

            return $this->getPermissionClass()::{$method}($permission, $this->getDefaultGuardName());
        }, Arr::wrap($permissions));
    }

    /**
     * Find a permission.
     *
     * @param  string|int|Permission|\BackedEnum  $permission
     * @return Permission
     *
     * @throws PermissionDoesNotExist
     */
    public function filterPermission($permission, $guardName = null)
    {
        if ($permission instanceof \BackedEnum) {
            $permission = $permission->value;
        }

        if (is_int($permission) || PermissionRegistrar::isUid($permission)) {
            $permission = $this->getPermissionClass()::findById(
                $permission,
                $guardName ?? $this->getDefaultGuardName()
            );
        }

        if (is_string($permission)) {
            $permission = $this->getPermissionClass()::findByName(
                $permission,
                $guardName ?? $this->getDefaultGuardName()
            );
        }

        if (! $permission instanceof Permission) {
            throw new PermissionDoesNotExist;
        }

        return $permission;
    }

    /**
     * Determine if the model may perform the given permission.
     *
     * @param  string|int|Permission|\BackedEnum  $permission
     * @param  string|null  $guardName
     *
     * @throws PermissionDoesNotExist
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        if ($this->getWildcardClass()) {
            return $this->hasWildcardPermission($permission, $guardName);
        }

        $permission = $this->filterPermission($permission, $guardName);

        return $this->hasDirectPermission($permission) || $this->hasPermissionViaRole($permission);
    }

    /**
     * Validates a wildcard permission against all permissions of a user.
     *
     * @param  string|int|Permission|\BackedEnum  $permission
     * @param  string|null  $guardName
     */
    protected function hasWildcardPermission($permission, $guardName = null): bool
    {
        $guardName = $guardName ?? $this->getDefaultGuardName();

        if ($permission instanceof \BackedEnum) {
            $permission = $permission->value;
        }

        if (is_int($permission) || PermissionRegistrar::isUid($permission)) {
            $permission = $this->getPermissionClass()::findById($permission, $guardName);
        }

        if ($permission instanceof Permission) {
            $guardName = $permission->guard_name ?? $guardName;
            $permission = $permission->name;
        }

        if (! is_string($permission)) {
            throw WildcardPermissionInvalidArgument::create();
        }

        return app($this->getWildcardClass(), ['record' => $this])->implies(
            $permission,
            $guardName,
            app(PermissionRegistrar::class)->getWildcardPermissionIndex($this),
        );
    }

    /**
     * An alias to hasPermissionTo(), but avoids throwing an exception.
     *
     * @param  string|int|Permission|\BackedEnum  $permission
     * @param  string|null  $guardName
     */
    public function checkPermissionTo($permission, $guardName = null): bool
    {
        try {
            return $this->hasPermissionTo($permission, $guardName);
        } catch (PermissionDoesNotExist $e) {
            return false;
        }
    }

    /**
     * Determine if the model has any of the given permissions.
     *
     * @param  string|int|array|Permission|Collection|\BackedEnum  ...$permissions
     */
    public function hasAnyPermission(...$permissions): bool
    {
        $permissions = collect($permissions)->flatten();

        foreach ($permissions as $permission) {
            if ($this->checkPermissionTo($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the model has all of the given permissions.
     *
     * @param  string|int|array|Permission|Collection|\BackedEnum  ...$permissions
     */
    public function hasAllPermissions(...$permissions): bool
    {
        $permissions = collect($permissions)->flatten();

        foreach ($permissions as $permission) {
            if (! $this->checkPermissionTo($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if the model has, via roles, the given permission.
     */
    protected function hasPermissionViaRole(Permission $permission): bool
    {
        if (is_a($this, Role::class)) {
            return false;
        }

        return $this->hasRole($permission->roles);
    }

    /**
     * Determine if the model has the given permission.
     *
     * @param  string|int|Permission|\BackedEnum  $permission
     *
     * @throws PermissionDoesNotExist
     */
    public function hasDirectPermission($permission): bool
    {
        $permission = $this->filterPermission($permission);

        return $this->loadMissing('permissions')->permissions
            ->contains($permission->getKeyName(), $permission->getKey());
    }

    /**
     * Return all the permissions the model has via roles.
     */
    public function getPermissionsViaRoles(): Collection
    {
        if (is_a($this, Role::class) || is_a($this, Permission::class)) {
            return collect();
        }

        return $this->loadMissing('roles', 'roles.permissions')
            ->roles->flatMap(fn ($role) => $role->permissions)
            ->sort()->values();
    }

    /**
     * Return all the permissions the model has, both directly and via roles.
     */
    public function getAllPermissions(): Collection
    {
        /** @var Collection $permissions */
        $permissions = $this->permissions;

        if (! is_a($this, Permission::class)) {
            $permissions = $permissions->merge($this->getPermissionsViaRoles());
        }

        return $permissions->sort()->values();
    }

    /**
     * Returns array of permissions ids
     *
     * @param  string|int|array|Permission|Collection|\BackedEnum  $permissions
     */
    private function collectPermissions(...$permissions): array
    {
        return collect($permissions)
            ->flatten()
            ->reduce(function ($array, $permission) {
                if (empty($permission)) {
                    return $array;
                }

                $permission = $this->getStoredPermission($permission);
                if (! $permission instanceof Permission) {
                    return $array;
                }

                if (! in_array($permission->getKey(), $array)) {
                    $this->ensureModelSharesGuard($permission);
                    $array[] = $permission->getKey();
                }

                return $array;
            }, []);
    }

    /**
     * Grant the given permission(s) to a role.
     *
     * @param  string|int|array|Permission|Collection|\BackedEnum  $permissions
     * @return $this
     */
    public function givePermissionTo($permissionType, ...$permissions)
    {
        if ($permissionType instanceof \BackedEnum) {
            $permissionType = $permissionType->value;
        } elseif (empty($permissionType)) {
            $permissionType = 'all';
        }

        $permissions = $this->collectPermissions($permissions);

        $model = $this->getModel();
        $pivotData = app(PermissionRegistrar::class)->teams && ! is_a($this, Role::class) ?
            [app(PermissionRegistrar::class)->teamsKey => getPermissionsTeamId()] : [];

        $pivotData['permission_type'] = $permissionType;

        if ($model->exists) {
            $currentPermissions = $this->permissions->map(fn ($permission) => $permission->getKey())->toArray();

            $this->permissions()->attach(array_diff($permissions, $currentPermissions), $pivotData);
            $model->unsetRelation('permissions');
        } else {
            $class = \get_class($model);
            $saved = false;

            $class::saved(
                function ($object) use ($permissions, $model, $pivotData, &$saved) {
                    if ($saved || $model->getKey() != $object->getKey()) {
                        return;
                    }

                    $model->permissions()->attach($permissions, $pivotData);
                    $model->unsetRelation('permissions');
                    $saved = true;
                }
            );
        }

        if (is_a($this, Role::class)) {
            $this->forgetCachedPermissions();
        }

        if (config('permission.events_enabled')) {
            event(new PermissionAttached($this->getModel(), $permissions));
        }

        $this->forgetWildcardPermissionIndex();

        return $this;
    }

    public function forgetWildcardPermissionIndex(): void
    {
        app(PermissionRegistrar::class)->forgetWildcardPermissionIndex(
            is_a($this, Role::class) ? null : $this,
        );
    }

    /**
     * Remove all current permissions and set the given ones.
     *
     * @param  string|int|array|Permission|Collection|\BackedEnum  $permissions
     * @return $this
     */
    public function syncPermissions($permissionType, ...$permissions)
    {
        if ($permissionType instanceof \BackedEnum) {
            $permissionType = $permissionType->value;
        } elseif (empty($permissionType)) {
            $permissionType = 'all';
        }

        if ($this->getModel()->exists) {
            $this->collectPermissions($permissions);
            $this->permissions()->detach();
            $this->setRelation('permissions', collect());
        }

        return $this->givePermissionTo($permissionType, $permissions);
    }

    /**
     * Revoke the given permission(s).
     *
     * @param  Permission|Permission[]|string|string[]|\BackedEnum  $permission
     * @return $this
     */
    public function revokePermissionTo($permission)
    {
        $storedPermission = $this->getStoredPermission($permission);

        $this->permissions()->detach($storedPermission);

        if (is_a($this, Role::class)) {
            $this->forgetCachedPermissions();
        }

        if (config('permission.events_enabled')) {
            event(new PermissionDetached($this->getModel(), $storedPermission));
        }

        $this->forgetWildcardPermissionIndex();

        $this->unsetRelation('permissions');

        return $this;
    }

    public function getPermissionNames(): Collection
    {
        return $this->permissions->pluck('name');
    }

    /**
     * @param  string|int|array|Permission|Collection|\BackedEnum  $permissions
     * @return Permission|Permission[]|Collection
     */
    protected function getStoredPermission($permissions)
    {
        if ($permissions instanceof \BackedEnum) {
            $permissions = $permissions->value;
        }

        if (is_int($permissions) || PermissionRegistrar::isUid($permissions)) {
            return $this->getPermissionClass()::findById($permissions, $this->getDefaultGuardName());
        }

        if (is_string($permissions)) {
            return $this->getPermissionClass()::findByName($permissions, $this->getDefaultGuardName());
        }

        if (is_array($permissions)) {
            $permissions = array_map(function ($permission) {
                if ($permission instanceof \BackedEnum) {
                    return $permission->value;
                }

                return is_a($permission, Permission::class) ? $permission->name : $permission;
            }, $permissions);

            return $this->getPermissionClass()::whereIn('name', $permissions)
                ->whereIn('guard_name', $this->getGuardNames())
                ->get();
        }

        return $permissions;
    }

    /**
     * @param  Permission|Role  $roleOrPermission
     *
     * @throws GuardDoesNotMatch
     */
    protected function ensureModelSharesGuard($roleOrPermission)
    {
        if (! $this->getGuardNames()->contains($roleOrPermission->guard_name)) {
            throw GuardDoesNotMatch::create($roleOrPermission->guard_name, $this->getGuardNames());
        }
    }

    protected function getGuardNames(): Collection
    {
        return Guard::getNames($this);
    }

    protected function getDefaultGuardName(): string
    {
        return Guard::getDefaultName($this);
    }

    /**
     * Forget the cached permissions.
     */
    public function forgetCachedPermissions()
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Check if the model has All of the requested Direct permissions.
     *
     * @param  string|int|array|Permission|Collection|\BackedEnum  ...$permissions
     */
    public function hasAllDirectPermissions(...$permissions): bool
    {
        $permissions = collect($permissions)->flatten();

        foreach ($permissions as $permission) {
            if (! $this->hasDirectPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if the model has Any of the requested Direct permissions.
     *
     * @param  string|int|array|Permission|Collection|\BackedEnum  ...$permissions
     */
    public function hasAnyDirectPermission(...$permissions): bool
    {
        $permissions = collect($permissions)->flatten();

        foreach ($permissions as $permission) {
            if ($this->hasDirectPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the model has the given permission with a specific permission_type in the pivot.
     *
     * @param  string|int|Permission|\BackedEnum  $permission
     */
    public function hasPermissionWithType($permission, string|array $permissionType, $guardName = null): bool
    {
        try {
            $permission = $this->filterPermission($permission, $guardName);
        } catch (PermissionDoesNotExist $e) {
            return false;
        }

        // Load permissions relationship with pivot if not loaded
        $this->loadMissing('permissions');

        // Check if the permission exists with the given permission_type in the pivot
        return $this->permissions->where($permission->getKeyName(), $permission->getKey())->contains(function ($perm) use ($permission, $permissionType) {
            if ($perm->getKey() !== $permission->getKey()) {
                return false;
            }

            // Check pivot permission_type
            return isset($perm->pivot) && (is_array($permissionType) ? in_array($perm->pivot->permission_type, $permissionType) : $perm->pivot->permission_type === $permissionType);
        });
    }
}
