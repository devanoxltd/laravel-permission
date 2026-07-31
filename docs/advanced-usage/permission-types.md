---
title: Permission Types
weight: 12
---

# Permission Types

Permission types allow you to attach additional context to a permission assignment. For example, a `create_post` permission can be scoped to mean the user may create posts in **all** contexts, only posts they **add**, only their **own** posts, **both** added and own posts, or **none** at all.

## Configuration

The pivot column name and default value are configurable in `config/permission.php`:

```php
'column_names' => [
    // ...
    'permission_type' => 'permission_type',
],

'permission_type_default' => 'none',
```

## Enum

The package ships with a `Spatie\Permission\PermissionType` enum covering the most common cases:

```php
use Spatie\Permission\PermissionType;

PermissionType::All->value;   // 'all'
PermissionType::Add->value; // 'add'
PermissionType::Own->value;   // 'own'
PermissionType::Both->value;  // 'both'
PermissionType::None->value;  // 'none'
```

You may also pass plain strings anywhere a type is accepted.

## Assigning permissions with a type

Use `givePermissionToWithType()` to assign one or more permissions with an explicit type:

```php
use Spatie\Permission\PermissionType;

$user->givePermissionToWithType(PermissionType::Own, 'create_post');
$role->givePermissionToWithType('add', 'edit_post');
```

Calling `givePermissionTo()` still works and stores the configured default type (`'none'` by default).

A permission can only have one type assigned at a time for a given model or role. If you assign the same permission again with a different type, it will **update** the existing type:

```php
$user->givePermissionToWithType(PermissionType::Own, 'create_post');
// Type is now 'own'

$user->givePermissionToWithType(PermissionType::Add, 'create_post');
// Type is updated to 'add'
```

## Retrieving a Permission Type

If you want to know the specific type of a permission that a user holds, you can use the `getPermissionType()` method. This will check direct permissions first, and then check inherited roles, returning the type string (or `null` if they don't have the permission at all):

```php
$type = $user->getPermissionType('create_post'); 
// Returns 'own', 'add', 'all', 'none', or null
```

## Checking permissions with a type

`hasPermissionTo()` continues to return `true` whenever the permission is assigned, regardless of type. To check a specific type, use `hasPermissionWithType()`:

```php
$user->hasPermissionWithType('create_post', PermissionType::Add); // true
$user->hasPermissionWithType('create_post', PermissionType::Own); // false
$user->hasPermissionWithType('create_post', PermissionType::All); // false
```

You can also check against multiple permissions using `hasAnyPermissionWithType()` and `hasAllPermissionsWithType()`:

```php
$user->hasAnyPermissionWithType(PermissionType::Own, 'create_post', 'edit_post');
$user->hasAllPermissionsWithType(PermissionType::Own, 'create_post', 'edit_post');
```

This works for both direct permissions and permissions inherited through roles.

## Syncing permissions with a type

Use `syncPermissionsWithType()` to replace the current direct permissions with a set of permissions that all share the same type:

```php
$user->syncPermissionsWithType(PermissionType::All, 'create_post', 'edit_post');
```

## Blade Directives

You can check permission types directly in your Blade templates using the `@haspermissionwithtype` directive:

```blade
@haspermissionwithtype('edit_post', \Spatie\Permission\PermissionType::Own)
    <button>Edit My Post</button>
@else
    <span>You cannot edit this.</span>
@endhaspermissionwithtype
```

You also have access to `@hasanypermissionwithtype` and `@hasallpermissionswithtype`:

```blade
@hasanypermissionwithtype(\Spatie\Permission\PermissionType::Own, 'edit_post', 'delete_post')
    <button>Manage My Posts</button>
@endhasanypermissionwithtype
```

## Middleware Support

To protect routes based on a permission and its required type, you can use the `permission_type` middleware:

```php
Route::post('/posts/{post}/edit', [PostController::class, 'edit'])
    ->middleware('permission_type:edit_post,own');
```

Alternatively, you can use the Route macro for a cleaner, more fluent syntax:

```php
use Spatie\Permission\PermissionType;

Route::post('/posts/{post}/edit', [PostController::class, 'edit'])
    ->permissionWithType('edit_post', PermissionType::Own);
```

## Migration

Fresh installs automatically receive the `permission_type` column on both `model_has_permissions` and `role_has_permissions`.

If you are upgrading an existing application, copy the `add_permission_type_fields.php.stub` migration from this package to your `database/migrations` directory, rename it with the current timestamp, and run `php artisan migrate`.
