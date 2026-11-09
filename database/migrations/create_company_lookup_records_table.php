<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local copy of the bulk company registers (Latvia, Estonia, Belgium), filled by `company-lookup:import`.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('company-lookup.bulk.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        $table = $this->tableName();
        $schema = Schema::connection($this->getConnection());

        // The package loads this migration itself when bulk is enabled; a published copy must not fail on it.
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('dataset', 32)->index();
            $table->string('country', 2);
            $table->string('identifier', 64);
            $table->text('name');
            $table->string('normalized_name', 255)->index();
            $table->text('address')->nullable();
            $table->string('status', 16);
            $table->string('legal_form')->nullable();
            $table->date('founded_at')->nullable();
            $table->json('payload')->nullable();
            $table->dateTime('imported_at', 6);

            $table->unique(['dataset', 'identifier']);
            $table->index(['dataset', 'imported_at']);
        });
    }

    /**
     * Intentionally empty: the package-loaded migration and a published copy share one table, so rolling
     * back either must not drop the data the other still relies on. Drop the table by hand if needed.
     */
    public function down(): void {}

    private function tableName(): string
    {
        $table = config('company-lookup.bulk.table');

        return is_string($table) && $table !== '' ? $table : 'company_lookup_records';
    }
};
