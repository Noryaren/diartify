<?php
declare(strict_types=1);

/**
 * Library scanner CLI.
 *
 *   php bin/scan.php            # incremental (only new/changed files)
 *   php bin/scan.php --full     # re-read every tag
 *   php bin/scan.php --quiet    # only the final summary
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/tags.php';
require __DIR__ . '/../app/scanner.php';

$full  = in_array('--full', $argv, true);
$quiet = in_array('--quiet', $argv, true);

set_time_limit(0);
ini_set('memory_limit', '512M');
ini_set('display_errors', '1');

if ($full) {
    echo "Mode penuh: semua file akan dibaca ulang.\n";
    db()->exec('UPDATE songs SET file_mtime = 0, file_size = 0');
}

$roots = library_paths();
if ($roots === []) {
    fwrite(STDERR, "music_paths kosong — cek config/config.php\n");
    exit(1);
}

echo "Folder musik:\n";
foreach ($roots as $root) {
    echo '  - ' . $root . (is_dir($root) ? '' : '  (TIDAK ADA)') . "\n";
}

$started = microtime(true);

$progress = null;
if (!$quiet) {
    $progress = function (array $stats): void {
        static $last = 0;
        if ($stats['files'] - $last >= 100) {
            $last = $stats['files'];
            printf(
                "  %d file | +%d baru | ~%d diperbarui | -%d hapus | %d galat\n",
                $stats['files'],
                $stats['added'],
                $stats['updated'],
                $stats['removed'],
                $stats['errors']
            );
        }
    };
}

try {
    $stats = scanner_run($progress);
} catch (Throwable $e) {
    fwrite(STDERR, 'Scan gagal: ' . $e->getMessage() . "\n");
    exit(1);
}

printf(
    "Selesai: %d file, %d lagu baru, %d diperbarui, %d dihapus, %d galat (%.1f dtk).\n",
    $stats['files'],
    $stats['added'],
    $stats['updated'],
    $stats['removed'],
    $stats['errors'],
    $stats['elapsed'] ?? (microtime(true) - $started)
);
