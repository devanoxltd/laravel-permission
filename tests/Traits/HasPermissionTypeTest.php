<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionType;
use Spatie\Permission\Support\Config;
use Spatie\Permission\Tests\TestSupport\TestModels\User;

it('has a permission type enum with the expected values', function () {
    expect(PermissionType::All->value)->toBe('all')
        ->and(PermissionType::Add->value)->toBe('add')
        ->and(PermissionType::Own->value)->toBe('own')
        ->and(PermissionType::Both->value)->toBe('both')
        ->and(PermissionType::None->value)->toBe('none');
});

it('creates the permission_type column on permission pivot tables', function () {
    $tableNames = config('permission.table_names');

    expect(Schema::hasColumn($tableNames['model_has_permissions'], Config::permissionTypeColumn()))->toBeTrue();
    expect(Schema::hasColumn($tableNames['role_has_permissions'], Config::permissionTypeColumn()))->toBeTrue();
});

it('assigns the default permission type when using givePermissionTo', function () {
    $this->testUser->givePermissionTo($this->testUserPermission);

    $pivot = DB::table(Config::modelHasPermissionsTable())
        ->where(Config::morphKey(), $this->testUser->getKey())
        ->where(app(PermissionRegistrar::class)->pivotPermission, $this->testUserPermission->getKey())
        ->first();

    expect($pivot->{Config::permissionTypeColumn()})->toBe(Config::permissionTypeDefault());
});

it('can assign a permission with a specific type using the enum', function () {
    $this->testUser->givePermissionToWithType(PermissionType::Own, $this->testUserPermission);

    expect($this->testUser->hasPermissionWithType($this->testUserPermission, PermissionType::Own))->toBeTrue();
    expect($this->testUser->hasPermissionWithType($this->testUserPermission, PermissionType::All))->toBeFalse();
});

it('can assign a permission with a specific type using a string', function () {
    $this->testUser->givePermissionToWithType('add', $this->testUserPermission);

    expect($this->testUser->hasPermissionWithType($this->testUserPermission, 'add'))->toBeTrue();
    expect($this->testUser->hasPermissionWithType($this->testUserPermission, 'own'))->toBeFalse();
});

it('can assign multiple permissions with a specific type', function () {
    $permission1 = app(Permission::class)->create(['name' => 'type-test-edit-news']);
    $permission2 = app(Permission::class)->create(['name' => 'type-test-edit-blog']);

    $this->testUser->givePermissionToWithType(PermissionType::Both, $permission1, $permission2);

    expect($this->testUser->hasPermissionWithType($permission1, PermissionType::Both))->toBeTrue();
    expect($this->testUser->hasPermissionWithType($permission2, PermissionType::Both))->toBeTrue();
    expect($this->testUser->hasPermissionWithType($permission1, PermissionType::Own))->toBeFalse();
});

it('updates the permission type when assigning the same permission with a different type', function () {
    $this->testUser->givePermissionToWithType(PermissionType::Own, $this->testUserPermission);
    $this->testUser->givePermissionToWithType(PermissionType::Add, $this->testUserPermission);

    expect($this->testUser->hasPermissionWithType($this->testUserPermission, PermissionType::Own))->toBeFalse();
    expect($this->testUser->hasPermissionWithType($this->testUserPermission, PermissionType::Add))->toBeTrue();

    $pivots = DB::table(Config::modelHasPermissionsTable())
        ->where(Config::morphKey(), $this->testUser->getKey())
        ->where(app(PermissionRegistrar::class)->pivotPermission, $this->testUserPermission->getKey())
        ->get();

    expect($pivots)->toHaveCount(1);
    expect($pivots->first()->{Config::permissionTypeColumn()})->toBe(PermissionType::Add->value);
});

it('can sync permissions with a specific type', function () {
    $permission1 = app(Permission::class)->create(['name' => 'type-test-sync-news']);

    $this->testUser->givePermissionToWithType(PermissionType::Own, $this->testUserPermission);
    $this->testUser->syncPermissionsWithType(PermissionType::All, $permission1);

    expect($this->testUser->hasPermissionWithType($this->testUserPermission, PermissionType::Own))->toBeFalse();
    expect($this->testUser->hasPermissionWithType($permission1, PermissionType::All))->toBeTrue();
});

it('can check permission type via a role', function () {
    $this->testUserRole->givePermissionToWithType(PermissionType::Own, $this->testUserPermission);
    $this->testUser->assignRole($this->testUserRole);

    expect($this->testUser->hasPermissionWithType($this->testUserPermission, PermissionType::Own))->toBeTrue();
    expect($this->testUser->hasPermissionWithType($this->testUserPermission, PermissionType::All))->toBeFalse();
});

it('can check permission type directly and via a role at the same time', function () {
    $directPermission = app(Permission::class)->create(['name' => 'type-test-direct-news']);

    $this->testUser->givePermissionToWithType(PermissionType::Own, $directPermission);
    $this->testUserRole->givePermissionToWithType(PermissionType::All, $this->testUserPermission);
    $this->testUser->assignRole($this->testUserRole);

    expect($this->testUser->hasPermissionWithType($directPermission, PermissionType::Own))->toBeTrue();
    expect($this->testUser->hasPermissionWithType($this->testUserPermission, PermissionType::All))->toBeTrue();
    expect($this->testUser->hasPermissionWithType($this->testUserPermission, PermissionType::Own))->toBeFalse();
});

it('keeps hasPermissionTo independent of the permission type', function () {
    $this->testUser->givePermissionToWithType(PermissionType::Own, $this->testUserPermission);

    expect($this->testUser->hasPermissionTo($this->testUserPermission))->toBeTrue();
});

it('exposes the permission type on the permissions relation pivot', function () {
    $this->testUser->givePermissionToWithType(PermissionType::Both, $this->testUserPermission);

    $permission = $this->testUser->permissions->first();

    expect($permission->pivot->{Config::permissionTypeColumn()})->toBe(PermissionType::Both->value);
});

it('exposes the permission type on the role permissions relation pivot', function () {
    $this->testUserRole->givePermissionToWithType(PermissionType::Add, $this->testUserPermission);

    $permission = $this->testUserRole->permissions->first();

    expect($permission->pivot->{Config::permissionTypeColumn()})->toBe(PermissionType::Add->value);
});

it('can scope users by permission regardless of type', function () {
    User::all()->each(fn ($item) => $item->delete());

    $user1 = User::create(['email' => 'user1@test.com']);
    $user2 = User::create(['email' => 'user2@test.com']);

    $user1->givePermissionToWithType(PermissionType::Own, $this->testUserPermission);
    $user2->givePermissionToWithType(PermissionType::All, $this->testUserPermission);

    expect(User::permission($this->testUserPermission)->count())->toBe(2);
});

it('can get the assigned permission type', function () {
    $this->testUser->givePermissionToWithType(PermissionType::Own, $this->testUserPermission);
    
    expect($this->testUser->getPermissionType($this->testUserPermission))->toBe(PermissionType::Own->value);
    
    // Test via role
    $this->testUser->permissions()->detach();
    $this->testUser->unsetRelation('permissions');
    $this->testUserRole->givePermissionToWithType(PermissionType::Add, $this->testUserPermission);
    $this->testUser->assignRole($this->testUserRole);
    
    expect($this->testUser->getPermissionType($this->testUserPermission))->toBe(PermissionType::Add->value);
    
    // Unassigned
    $this->testUser->roles()->detach();
    $this->testUser->unsetRelation('roles');
    $this->testUser->unsetRelation('permissions');
    expect($this->testUser->getPermissionType($this->testUserPermission))->toBeNull();
});

it('can determine if user has any or all permissions with type', function () {
    $permission2 = app(\Spatie\Permission\Contracts\Permission::class)::where('name', 'edit-news')->first();
    
    $this->testUser->givePermissionToWithType(\Spatie\Permission\PermissionType::Own, $this->testUserPermission);
    $this->testUser->givePermissionToWithType(\Spatie\Permission\PermissionType::Add, $permission2);
    
    expect($this->testUser->hasAnyPermissionWithType(\Spatie\Permission\PermissionType::Own, $this->testUserPermission, $permission2))->toBeTrue();
    expect($this->testUser->hasAnyPermissionWithType(\Spatie\Permission\PermissionType::All, $this->testUserPermission, $permission2))->toBeFalse();
    
    expect($this->testUser->hasAllPermissionsWithType(\Spatie\Permission\PermissionType::Own, $this->testUserPermission, $permission2))->toBeFalse();
    
    $this->testUser->givePermissionToWithType(\Spatie\Permission\PermissionType::Own, $permission2);
    expect($this->testUser->hasAllPermissionsWithType(\Spatie\Permission\PermissionType::Own, $this->testUserPermission, $permission2))->toBeTrue();
});
