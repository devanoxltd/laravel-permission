<?php

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionTypeMiddleware;
use Spatie\Permission\PermissionType;

beforeEach(function () {
    $this->middleware = new PermissionTypeMiddleware;
});

it('throws unauthorized exception if user is not logged in', function () {
    $request = Request::create('/test-middleware');

    $this->middleware->handle($request, function () {
        return (new Response)->setContent('<html></html>');
    }, $this->testUserPermission->name, PermissionType::Own->value);
})->throws(UnauthorizedException::class, 'User is not logged in.');

it('throws unauthorized exception if user does not have permission with type', function () {
    Auth::login($this->testUser);

    $request = Request::create('/test-middleware');

    $this->testUser->givePermissionToWithType(PermissionType::Add, $this->testUserPermission);

    $this->middleware->handle($request, function () {
        return (new Response)->setContent('<html></html>');
    }, $this->testUserPermission->name, PermissionType::Own->value);
})->throws(UnauthorizedException::class, "User does not have the right permissions.");

it('passes if user has permission with type directly', function () {
    Auth::login($this->testUser);

    $request = Request::create('/test-middleware');

    $this->testUser->givePermissionToWithType(PermissionType::Own, $this->testUserPermission);

    $response = $this->middleware->handle($request, function () {
        return (new Response)->setContent('<html></html>');
    }, $this->testUserPermission->name, PermissionType::Own->value);

    expect($response->getStatusCode())->toBe(200);
});
