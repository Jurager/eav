<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    private const MIN_SERVER_VERSION = 120000;

    private const COLLATION = 'case_insensitive';

    private const COLLATION_LOCALE = 'und-u-ks-level2';

    private const EXTENSIONS = ['citext', 'pg_trgm'];

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        foreach (self::EXTENSIONS as $extension) {
            $this->createExtension($extension);
        }

        $this->createCollation();
    }

    /**
     * Drop the collation only.
     *
     * Extensions are shared database resources: another schema in the same database may rely on them.
     * So installing one does not make this migration their owner.
     */
    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $this->connection()->statement(
            sprintf('DROP COLLATION IF EXISTS %s', self::COLLATION)
        );
    }

    private function connection(): Connection
    {
        return DB::connection($this->getConnection());
    }

    private function isPostgres(): bool
    {
        return $this->connection()->getDriverName() === 'pgsql';
    }

    private function createExtension(string $name): void
    {
        $connection = $this->connection();

        if ($connection->selectOne('SELECT 1 FROM pg_extension WHERE extname = ?', [$name])) {
            return;
        }

        if (! $connection->selectOne('SELECT 1 FROM pg_available_extensions WHERE name = ?', [$name])) {
            throw new RuntimeException("PostgreSQL extension [{$name}] not available.");
        }

        $connection->statement(sprintf('CREATE EXTENSION IF NOT EXISTS %s', $name));
    }

    private function createCollation(): void
    {
        $connection = $this->connection();

        $version = (int) $connection->selectOne("SELECT current_setting('server_version_num') AS v")->v;

        if ($version < self::MIN_SERVER_VERSION) {
            throw new RuntimeException(
                'Non-deterministic collations require PostgreSQL 12+, current version: '.$version
            );
        }

        $icuAvailable = (int) $connection->selectOne("SELECT count(*) AS c FROM pg_collation WHERE collprovider = 'i'")->c > 0;

        if (! $icuAvailable) {
            throw new RuntimeException('PostgreSQL is compiled without ICU support - collation cannot be created.');
        }

        $connection->statement(sprintf(<<<'SQL'
            CREATE COLLATION IF NOT EXISTS %s
            (
                provider = icu,
                locale = '%s',
                deterministic = false
            )
        SQL, self::COLLATION, self::COLLATION_LOCALE));
    }
};
