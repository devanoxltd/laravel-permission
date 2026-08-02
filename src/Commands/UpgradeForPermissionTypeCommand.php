<?php

namespace Spatie\Permission\Commands;

use Illuminate\Console\Command;

class UpgradeForPermissionTypeCommand extends Command
{
    protected $signature = 'permission:setup-permission-type';

    protected $description = 'Setup the permission type feature by generating the associated migration.';

    protected string $migrationSuffix = 'add_permission_type_fields.php';

    public function handle(): int
    {
        $this->line('');
        $this->info(__('permission::messages.permission_type_setup_adding_migration'));

        $existingMigrations = $this->alreadyExistingMigrations();

        if ($existingMigrations) {
            $this->line('');

            $this->warn($this->getExistingMigrationsWarning($existingMigrations));
        }

        $this->line('');

        if (! $this->confirm(__('permission::messages.proceed_with_migration_creation'), true)) {
            return self::SUCCESS;
        }

        $this->line('');

        $this->line(__('permission::messages.creating_migration'));

        if ($this->createMigration()) {
            $this->info(__('permission::messages.migration_created_successfully'));
        } else {
            $this->error(__('permission::messages.couldnt_create_migration'));
        }

        $this->line('');

        return self::SUCCESS;
    }

    protected function createMigration(): bool
    {
        try {
            $migrationStub = __DIR__."/../../database/migrations/{$this->migrationSuffix}.stub";
            copy($migrationStub, $this->getMigrationPath());

            return true;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return false;
        }
    }

    protected function getExistingMigrationsWarning(array $existingMigrations): string
    {
        if (count($existingMigrations) > 1) {
            $base = __('permission::messages.setup_permission_type_migrations_already_exist');
        } else {
            $base = __('permission::messages.setup_permission_type_migration_already_exists');
        }

        return $base.array_reduce($existingMigrations, fn ($carry, $fileName) => $carry."\n - ".$fileName);
    }

    protected function alreadyExistingMigrations(): array
    {
        $matchingFiles = glob($this->getMigrationPath('*'));

        return array_map(fn ($path) => basename($path), $matchingFiles);
    }

    protected function getMigrationPath(?string $date = null): string
    {
        $date = $date ?: now()->format('Y_m_d_His');

        return database_path("migrations/{$date}_{$this->migrationSuffix}");
    }
}
