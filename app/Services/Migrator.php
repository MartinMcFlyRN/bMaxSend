<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Applies database/migrations/NNNN_name.(sql|php) not yet recorded in schema_migrations. */
final class Migrator
{
    /** @return string[] versions applied */
    public static function run(?\Closure $out = null): array
    {
        $done = array_flip(DB::column('SELECT version FROM schema_migrations'));
        $files = glob(BASE_PATH . '/database/migrations/*.{sql,php}', GLOB_BRACE) ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $version = pathinfo($file, PATHINFO_FILENAME);
            if (isset($done[$version])) {
                continue;
            }
            $out && $out("Applying $version ...");
            if (str_ends_with($file, '.php')) {
                (static function () use ($file) {
                    require $file;
                })();
            } else {
                $sql = preg_replace('/^--.*$/m', '', (string) file_get_contents($file));
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    DB::pdo()->exec($stmt);
                }
            }
            DB::query('INSERT INTO schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP())', [$version]);
            $applied[] = $version;
        }
        return $applied;
    }
}
