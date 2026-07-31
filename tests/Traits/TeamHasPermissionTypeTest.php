<?php

use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\PermissionType;
use Spatie\Permission\Support\Config;

beforeEach(fn () => $this->setUpTeams());

it('can assign a permission with a type in a team context', function () {
    $this->testUser->givePermissionToWithType(PermissionType::Own, $this->testUserPermission);

    expect($this->testUser->hasPermissionWithType($this->testUserPermission, PermissionType::Own))->toBeTrue();
});

it('isolates permission types between teams', function () {
    $this->testUser->givePermissionToWithType(PermissionType::Own, $this->testUserPermission);

    setPermissionsTeamId(2);

    expect($this->testUser->hasPermissionWithType($this->testUserPermission, PermissionType::Own))->toBeFalse();

    $this->testUser->givePermissionToWithType(PermissionType::All, $this->testUserPermission);

    expect($this->testUser->hasPermissionWithType($this->testUserPermission, PermissionType::All))->toBeTrue();
    expect($this->testUser->hasPermissionWithType($this->testUserPermission, PermissionType::Own))->toBeFalse();
});

it('includes the configured team and permission type columns on the pivot', function () {
    $this->testUser->givePermissionToWithType(PermissionType::Both, $this->testUserPermission);

    $permission = $this->testUser->permissions->first();

    expect($permission->pivot->{Config::permissionTypeColumn()})->toBe(PermissionType::Both->value)
        ->and($permission->pivot->{Config::teamForeignKey()})->toBe(getPermissionsTeamId());
});

it('can check permission type via a role in a team context', function () {
    $permission = app(Permission::class)->create(['name' => 'team-type-test-permission']);

    $this->testUserRole->givePermissionToWithType(PermissionType::Add, $permission);
    $this->testUser->assignRole($this->testUserRole);

    expect($this->testUser->hasPermissionWithType($permission, PermissionType::Add))->toBeTrue();
    expect($this->testUser->hasPermissionWithType($permission, PermissionType::Own))->toBeFalse();
});
