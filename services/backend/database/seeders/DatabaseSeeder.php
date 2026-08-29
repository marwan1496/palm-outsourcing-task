<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the database.
     *
     * Note there is no WithoutModelEvents here, deliberately. ProductObserver
     * bumps the product cache version whenever a product is saved, and
     * suppressing that would leave the API serving an empty catalogue from
     * cache while the database is full.
     *
     * No users are seeded either. The API authenticates with tokens rather
     * than logins, and `php artisan api:token` creates the account it needs.
     */
    public function run(): void
    {
        $this->call(ProductSeeder::class);
    }
}
