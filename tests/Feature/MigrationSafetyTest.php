<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('ensures every migration only targets pas_ tables', function () {
    $migrationPath = database_path('migrations');
    $files = File::glob("{$migrationPath}/*.php");

    if (empty($files)) {
        expect(true)->toBeTrue(); // Empty migrations folder is OK

        return;
    }

    $violations = [];

    foreach ($files as $file) {
        $contents = File::get($file);

        // Extract table names from Schema calls
        // Matches: Schema::create('table_name', ...), Schema::table('table_name', ...), etc.
        if (preg_match_all(
            "/Schema::(create|table|drop|dropIfExists|rename)\s*\(\s*['\"]([^'\"]+)['\"]/",
            $contents,
            $matches,
            PREG_PATTERN_ORDER
        )) {
            // For Schema::rename, we need to capture both table names
            if (preg_match_all(
                "/Schema::rename\s*\(\s*['\"]([^'\"]+)['\"]\s*,\s*['\"]([^'\"]+)['\"]/",
                $contents,
                $renameMatches,
                PREG_PATTERN_ORDER
            )) {
                foreach ($renameMatches[1] as $table) {
                    if (! str_starts_with($table, 'pas_')) {
                        $violations[] = [basename($file), $table];
                    }
                }
                foreach ($renameMatches[2] as $table) {
                    if (! str_starts_with($table, 'pas_')) {
                        $violations[] = [basename($file), $table];
                    }
                }
            }

            // Extract from regular Schema calls (excluding rename which we handled)
            $tableNames = array_filter(
                $matches[2],
                fn ($idx) => $matches[1][$idx] !== 'rename',
                ARRAY_FILTER_USE_KEY
            );

            foreach ($tableNames as $table) {
                if (! str_starts_with($table, 'pas_')) {
                    $violations[] = [basename($file), $table];
                }
            }
        }

        // Also check for DB::table() and DB::statement() write patterns
        if (preg_match_all(
            "/DB::(table|statement)\s*\(\s*['\"]([^'\"]+)['\"]/",
            $contents,
            $dbMatches,
            PREG_PATTERN_ORDER
        )) {
            foreach ($dbMatches[2] as $idx => $table) {
                // Only flag if it looks like a write operation follows
                // (this is a heuristic; we trust migrations are written correctly otherwise)
                if ($dbMatches[1][$idx] === 'table' && ! str_starts_with($table, 'pas_')) {
                    $violations[] = [basename($file), $table];
                }
            }
        }
    }

    if (! empty($violations)) {
        $message = "Migrations must only target tables with 'pas_' prefix:\n";
        foreach ($violations as [$file, $table]) {
            $message .= "  - {$file}: '{$table}'\n";
        }
        expect(true)->toBeFalse($message);
    }

    expect(true)->toBeTrue();
});

it('ensures every seeder only writes to pas_ tables', function () {
    $seedersPath = database_path('seeders');
    $files = File::glob("{$seedersPath}/*.php");

    if (empty($files)) {
        expect(true)->toBeTrue();

        return;
    }

    $violations = [];

    foreach ($files as $file) {
        $contents = File::get($file);

        // Check for LMS model writes (these would indicate mutation)
        if (preg_match('/App\\\\Models\\\\Lms\\\\/', $contents)) {
            if (preg_match('/(::create|->insert|->update|->delete|->save)/', $contents)) {
                $violations[] = basename($file);
            }
        }

        // Check for raw DB writes to non-pas_ tables
        if (preg_match_all(
            "/DB::(table|insert|update|delete|statement)\s*\(\s*['\"]([^'\"]+)['\"]/",
            $contents,
            $matches,
            PREG_PATTERN_ORDER
        )) {
            foreach ($matches[2] as $idx => $table) {
                if (! str_starts_with($table, 'pas_') && ! str_starts_with($table, 'users')) {
                    $violations[] = basename($file);
                }
            }
        }
    }

    if (! empty($violations)) {
        $message = 'Seeders must not write to LMS tables: '.implode(', ', array_unique($violations));
        expect(true)->toBeFalse($message);
    }

    expect(true)->toBeTrue();
});
