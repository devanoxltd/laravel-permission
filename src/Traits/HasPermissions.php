<?php

namespace Spatie\Permission\Traits;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Contracts\Role;
use Spatie\Permission\Contracts\Wildcard;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Exceptions\GuardDoesNotMatch;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\WildcardPermissionInvalidArgument;
use Spatie\Permission\Exceptions\WildcardPermissionNotImplementsContract;
use Spatie\Permission\Guard;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionType;
use Spatie\Permission\Support\Config;

use function Illuminate\Support\enum_value;

trait HasPermissions
{
    private ?string $permissionClass = null;

    private ?string $wildcardClass = null;

    private array $wildcardPermissionsIndex;

    public static function bootHasPermissions(): void
    {
        static::deleting(function ($model) {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $teams = app(PermissionRegistrar::class)->teams;
            app(PermissionRegistrar::class)->teams = false;
            if (! $model instanceof Permission) {
                $model->permissions()->detach();
            }
            if ($model instanceof Role) {
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

    public function getWildcardClass(): string
    {
        if (! is_null($this->wildcardClass)) {
            return $this->wildcardClass;
        }

        $this->wildcardClass = '';

        if (Config::wildcardPermissionsEnabled()) {
            $this->wildcardClass = Config::wildcardPermissionClass();

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
            Config::permissionModel(),
            'model',
            Config::modelHasPermissionsTable(),
            Config::morphKey(),
            app(PermissionRegistrar::class)->pivotPermission
        );

        $permissionTypeColumn = Config::permissionTypeColumn();

        if (! Config::teamsEnabled()) {
            return $relation->withPivot($permissionTypeColumn);
        }

        $teamsKey = Config::teamForeignKey();
        $relation->withPivot($teamsKey, $permissionTypeColumn);

        return $relation->wherePivot($teamsKey, getPermissionsTeamId());
    }

    /**
     * Scope the model query to certain permissions only.
     *
     * @param  string|int|array|Permission|Collection|BackedEnum  $permissions
     */
    public function scopePermission(Builder $query, $permissions, bool $without = false): Builder
    {
        $permissions = $this->convertToPermissionModels($permissions);

        $permissionKey = (new ($this->getPermissionClass())())->getKeyName();
        $roleKey = (new ($this instanceof Role ? static::class : $this->getRoleClass())())->getKeyName();

        $rolesWithPermissions = $this instanceof Role ? [] : array_unique(
            array_reduce($permissions, fn ($result, $permission) => array_merge($result, $permission->roles->all()), [])
        );

        return $query->where(fn (Builder $query) => $query
            ->{! $without ? 'whereHas' : 'whereDoesntHave'}('permissions', fn (Builder $subQuery) => $subQuery
            ->whereIn(Config::permissionsTable().".$permissionKey", array_column($permissions, $permissionKey))
            )
            ->when(count($rolesWithPermissions), fn ($whenQuery) => $whenQuery
                ->{! $without ? 'orWhereHas' : 'whereDoesntHave'}('roles', fn (Builder $subQuery) => $subQuery
                ->whereIn(Config::rolesTable().".$roleKey", array_column($rolesWithPermissions, $roleKey))
                )
            )
        );
    }

    /**
     * Scope the model query to only those without certain permissions,
     * whether indirectly by role or by direct permission.
     *
     * @param  string|int|array|Permission|Collection|BackedEnum  $permissions
     */
    public function scopeWithoutPermission(Builder $query, $permissions): Builder
    {
        return $this->scopePermission($query, $permissions, true);
    }

    /**
     * @param  string|int|array|Permission|Collection|BackedEnum  $permissions
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

            $permission = enum_value($permission);

            $method = is_int($permission) || PermissionRegistrar::isUid($permission) ? 'findById' : 'findByName';

            return $this->getPermissionClass()::{$method}($permission, $this->getDefaultGuardName());
        }, Arr::wrap($permissions));
    }

    /**
     * Find a permission.
     *
     * @param  string|int|Permission|BackedEnum  $permission
     *
     * @throws PermissionDoesNotExist
     */
    public function filterPermission($permission, ?string $guardName = null): Permission
    {
        $permission = enum_value($permission);

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
     * @param  string|int|Permission|BackedEnum  $permission
     *
     * @throws PermissionDoesNotExist
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
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
     * @param  string|int|Permission|BackedEnum  $permission
     */
    protected function hasWildcardPermission($permission, ?string $guardName = null): bool
    {
        $guardName = $guardName ?? $this->getDefaultGuardName();

        $permission = enum_value($permission);

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
     * @param  string|int|Permission|BackedEnum  $permission
     */
    public function checkPermissionTo($permission, ?string $guardName = null): bool
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
     * @param  string|int|array|Permission|Collection|BackedEnum  ...$permissions
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
     * @param  string|int|array|Permission|Collection|BackedEnum  ...$permissions
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
     * An alias to hasPermissionWithType(), but avoids throwing an exception.
     *
     * @param  string|int|Permission|BackedEnum  $permission
     */
    public function checkPermissionToWithType($permission, PermissionType|string $permissionType, ?string $guardName = null): bool
    {
        try {
            return $this->hasPermissionWithType($permission, $permissionType, $guardName);
        } catch (PermissionDoesNotExist $e) {
            return false;
        }
    }

    /**
     * Determine if the model has any of the given permissions with the given type.
     *
     * @param  PermissionType|string $permissionType
     * @param  string|int|array|Permission|Collection|BackedEnum  ...$permissions
     */
    public function hasAnyPermissionWithType(PermissionType|string $permissionType, ...$permissions): bool
    {
        $permissions = collect($permissions)->flatten();

        foreach ($permissions as $permission) {
            if ($this->checkPermissionToWithType($permission, $permissionType)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the model has all of the given permissions with the given type.
     *
     * @param  PermissionType|string $permissionType
     * @param  string|int|array|Permission|Collection|BackedEnum  ...$permissions
     */
    public function hasAllPermissionsWithType(PermissionType|string $permissionType, ...$permissions): bool
    {
        $permissions = collect($permissions)->flatten();

        foreach ($permissions as $permission) {
            if (! $this->checkPermissionToWithType($permission, $permissionType)) {
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
        if ($this instanceof Role) {
            return false;
        }

        return $this->hasRole($permission->roles);
    }

    /**
     * Determine if the model may perform the given permission with the given type.
     *
     * @param  string|int|Permission|BackedEnum  $permission
     *
     * @throws PermissionDoesNotExist
     */
    public function hasPermissionWithType($permission, PermissionType|string $permissionType, ?string $guardName = null): bool
    {
        $permission = $this->filterPermission($permission, $guardName);
        $permissionType = enum_value($permissionType);

        return $this->hasDirectPermissionWithType($permission, $permissionType)
            || $this->hasPermissionViaRoleWithType($permission, $permissionType);
    }

    /**
     * Determine if the model has the given permission with the given type directly.
     */
    protected function hasDirectPermissionWithType(Permission $permission, string $permissionType): bool
    {
        return $this->loadMissing('permissions')
            ->permissions
            ->where('pivot.'.Config::permissionTypeColumn(), $permissionType)
            ->contains($permission->getKeyName(), $permission->getKey());
    }

    /**
     * Determine if the model has the given permission with the given type via roles.
     */
    protected function hasPermissionViaRoleWithType(Permission $permission, string $permissionType): bool
    {
        if ($this instanceof Role) {
            return false;
        }

        $permission->load(['roles' => fn ($query) => $query->withPivot(Config::permissionTypeColumn())]);

        $roleKey = (new ($this->getRoleClass())())->getKeyName();

        $rolesWithPermissionOfType = $permission->roles
            ->where('pivot.'.Config::permissionTypeColumn(), $permissionType)
            ->pluck($roleKey);

        return $this->hasRole($rolesWithPermissionOfType->all());
    }

    /**
     * Get the assigned type for a given permission.
     * Returns null if the permission is not assigned.
     *
     * @param  string|int|Permission|BackedEnum  $permission
     */
    public function getPermissionType($permission, ?string $guardName = null): ?string
    {
        $permission = $this->filterPermission($permission, $guardName);

        // Check direct permissions
        $directPermission = $this->loadMissing('permissions')
            ->permissions
            ->where($permission->getKeyName(), $permission->getKey())
            ->first();

        if ($directPermission) {
            return $directPermission->pivot->{Config::permissionTypeColumn()};
        }

        // Check via roles
        if (! $this instanceof Role) {
            $permission->load(['roles' => fn ($query) => $query->withPivot(Config::permissionTypeColumn())]);

            $roleKey = (new ($this->getRoleClass())())->getKeyName();

            $rolesWithPermission = $permission->roles->whereIn($roleKey, $this->roles->pluck($roleKey));

            if ($rolesWithPermission->isNotEmpty()) {
                return $rolesWithPermission->first()->pivot->{Config::permissionTypeColumn()};
            }
        }

        return null;
    }

    /**
     * Determine if the model has the given permission.
     *
     * @param  string|int|Permission|BackedEnum  $permission
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
        // This trait is shared by both Role and Permission models; Larastan analyses it once per
        // consuming class, pinning $this to that one class, so it reports the check for the other
        // class as dead code even though both checks are needed at runtime.
        // The phpstan finding appears to be environment-dependent, invisible to the current CI runner
        // but reproducible on at least one real local dev setup (Mac + Herd + PHP 8.4.6).
        // Ignoring here so local and CI static-analysis results stay consistent for all contributors.
        // @phpstan-ignore instanceof.alwaysFalse
        if ($this instanceof Role || $this instanceof Permission) {
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

        if (! $this instanceof Permission) {
            $permissions = $permissions->merge($this->getPermissionsViaRoles());
        }

        return $permissions->sort()->values();
    }

    /**
     * Returns array of permissions ids
     *
     * @param  string|int|array|Permission|Collection|BackedEnum  $permissions
     */
    private function collectPermissions(...$permissions): array
    {
        return collect($permissions)
            ->flatten()
            ->reduce(function ($array, $permission) {
                if ($permission === null || $permission === '') {
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

    private function detachPermissions(?array $permissions = null): int
    {
        $relation = $this->permissions();

        if (! Config::teamsEnabled() || $this instanceof Role || $relation->getPivotClass() === Pivot::class) {
            return $relation->detach($permissions);
        }

        // Custom pivot deletes do not include the team key, so keep deletion on the scoped pivot query.
        $query = $relation->newPivotQuery();

        if (! is_null($permissions)) {
            if (empty($permissions)) {
                return 0;
            }

            $query->whereIn($relation->getQualifiedRelatedPivotKeyName(), $permissions);
        }

        $results = $query->delete();

        $relation->touchIfTouching();

        return $results;
    }

    /**
     * Grant the given permission(s) to a role.
     *
     * @param  string|int|array|Permission|Collection|BackedEnum  $permissions
     * @return $this
     */
    public function givePermissionTo(...$permissions): static
    {
        return $this->givePermissionToWithType(Config::permissionTypeDefault(), ...$permissions);
    }

    /**
     * Grant the given permission(s) with an explicit type to the model.
     *
     * @param  string|int|array|Permission|Collection|BackedEnum  $permissions
     * @return $this
     */
    public function givePermissionToWithType(PermissionType|string $permissionType, ...$permissions): static
    {
        $permissionType = enum_value($permissionType);
        $permissions = $this->collectPermissions($permissions);

        $model = $this->getModel();
        $teamPivot = app(PermissionRegistrar::class)->teams && ! $this instanceof Role ?
            [app(PermissionRegistrar::class)->teamsKey => getPermissionsTeamId()] : [];
        $permissionTypeColumn = Config::permissionTypeColumn();

        if ($model->exists) {
            $currentPermissions = $this->permissions
                ->mapWithKeys(fn ($permission) => [$permission->getKey() => $permission->pivot->{$permissionTypeColumn}])
                ->toArray();

            $newPermissions = array_diff($permissions, array_keys($currentPermissions));
            
            if (!empty($newPermissions)) {
                $attachData = array_fill_keys($newPermissions, [$permissionTypeColumn => $permissionType]);
                $attachData = array_map(fn ($pivot) => $pivot + $teamPivot, $attachData);
                $this->permissions()->attach($attachData);
            }

            $existingPermissions = array_intersect($permissions, array_keys($currentPermissions));
            foreach ($existingPermissions as $id) {
                if ($currentPermissions[$id] !== $permissionType) {
                    $this->permissions()->updateExistingPivot($id, [$permissionTypeColumn => $permissionType]);
                }
            }

            $model->unsetRelation('permissions');
        } else {
            $class = $model::class;
            $saved = false;

            $class::saved(
                function ($object) use ($permissions, $model, $permissionType, $teamPivot, $permissionTypeColumn, &$saved) {
                    if ($saved || $model->getKey() != $object->getKey()) {
                        return;
                    }
                    $attachData = array_fill_keys($permissions, [$permissionTypeColumn => $permissionType]);
                    $attachData = array_map(fn ($pivot) => $pivot + $teamPivot, $attachData);

                    $model->permissions()->attach($attachData);
                    $model->unsetRelation('permissions');
                    $saved = true;
                }
            );
        }

        if ($this instanceof Role) {
            $this->forgetCachedPermissions();
        }

        if (Config::eventsEnabled()) {
            event(new PermissionAttachedEvent($this->getModel(), $permissions));
        }

        $this->forgetWildcardPermissionIndex();

        return $this;
    }

    public function forgetWildcardPermissionIndex(): void
    {
        app(PermissionRegistrar::class)->forgetWildcardPermissionIndex(
            $this instanceof Role ? null : $this,
        );
    }

    /**
     * Remove all current permissions and set the given ones.
     *
     * @param  string|int|array|Permission|Collection|BackedEnum  $permissions
     * @return $this
     */
    public function syncPermissions(...$permissions): static
    {
        return $this->syncPermissionsWithType(Config::permissionTypeDefault(), ...$permissions);
    }

    /**
     * Remove all current permissions and set the given ones with an explicit type.
     *
     * @param  string|int|array|Permission|Collection|BackedEnum  $permissions
     * @return $this
     */
    public function syncPermissionsWithType(PermissionType|string $permissionType, ...$permissions): static
    {
        $permissionType = enum_value($permissionType);

        if ($this->getModel()->exists) {
            $this->collectPermissions($permissions);

            if (Config::eventsEnabled()) {
                $currentPermissions = $this->permissions()->get();

                if ($currentPermissions->isNotEmpty()) {
                    $this->revokePermissionTo($currentPermissions);
                }
            } else {
                $this->detachPermissions();
                $this->setRelation('permissions', collect());
            }
        }

        return $this->givePermissionToWithType($permissionType, $permissions);
    }

    /**
     * Revoke the given permission(s).
     *
     * @param  Permission|Permission[]|string|string[]|BackedEnum  $permission
     * @return $this
     */
    public function revokePermissionTo($permission): static
    {
        $storedPermission = $this->getStoredPermission($permission);
        $permissions = $this->collectPermissions($storedPermission);

        $this->detachPermissions($permissions);

        if ($this instanceof Role) {
            $this->forgetCachedPermissions();
        }

        if (Config::eventsEnabled()) {
            event(new PermissionDetachedEvent($this->getModel(), $storedPermission));
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
     * @param  string|int|array|Permission|Collection|BackedEnum  $permissions
     * @return Permission|Permission[]|Collection
     */
    protected function getStoredPermission($permissions)
    {
        $permissions = enum_value($permissions);

        if (is_int($permissions) || PermissionRegistrar::isUid($permissions)) {
            return $this->getPermissionClass()::findById($permissions, $this->getDefaultGuardName());
        }

        if (is_string($permissions)) {
            return $this->getPermissionClass()::findByName($permissions, $this->getDefaultGuardName());
        }

        if (is_array($permissions)) {
            $permissions = array_map(fn ($permission) => $permission instanceof Permission ? $permission->name : enum_value($permission), $permissions);

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
    protected function ensureModelSharesGuard($roleOrPermission): void
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
    public function forgetCachedPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Check if the model has All of the requested Direct permissions.
     *
     * @param  string|int|array|Permission|Collection|BackedEnum  ...$permissions
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
     * @param  string|int|array|Permission|Collection|BackedEnum  ...$permissions
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
}
