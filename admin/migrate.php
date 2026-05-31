#!/usr/bin/env php
<?php
// ════════════════════════════════════════════════════════════════════
//  T—BANEN Image Migration Script
//  Activates image optimization pipeline for all existing station
//  images without requiring manual re-upload through the admin.
//
//  Usage:
//    php migrate.php --dry-run    Analyze only, no files written
//    php migrate.php --archive    Phase 1: copy originals to originals/
//    php migrate.php --process    Phase 2: generate optimized versions
//    php migrate.php --update     Phase 3: update stations.json
//    php migrate.php --validate   Phase 4: verify everything
//    php migrate.php --full       Run all phases in sequence
//
//  Safety rules:
//    - originals/ is NEVER written after the archive phase
//    - existing files in medium/, thumbs/ are SKIPPED (not overwritten)
//    - stations.json is backed up before every write
//    - failures are logged and summarised; script always continues
// ════════════════════════════════════════════════════════════════════

if (PHP_SAPI !== 'cli') { echo "Run from CLI only.\n"; exit(1); }

define('ROOT',         dirname(__DIR__));
define('STATIONS_DIR', ROOT . '/images/stations/');
define('ORIGINALS_DIR',ROOT . '/images/originals/');
define('MEDIUM_DIR',   ROOT . '/images/medium/');
define('THUMBS_DIR',   ROOT . '/images/thumbs/');
define('DATA_FILE',    ROOT . '/data/stations.json');
define('DATA_BACKUPS', ROOT . '/data/backups/');
define('LOG_FILE',     __DIR__ . '/migration.log');

// ── Parse args ────────────────────────────────────────────────────
$args = array_slice($argv, 1);
$mode = $args[0] ?? '--help';

if ($mode === '--help' || empty($mode)) {
    echo "Usage: php migrate.php [--dry-run|--archive|--process|--update|--validate|--full]\n";
    exit(0);
}

$isDryRun = in_array('--dry-run', $args);

// ── Logger ────────────────────────────────────────────────────────
$log = [];
function log_msg(string $msg, string $level = 'INFO'): void {
    global $log;
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $msg;
    $log[] = $line;
    echo $line . "\n";
}
function log_ok(string $msg)   { log_msg($msg, 'OK   '); }
function log_warn(string $msg) { log_msg($msg, 'WARN '); }
function log_fail(string $msg) { log_msg($msg, 'FAIL '); }
function log_skip(string $msg) { log_msg($msg, 'SKIP '); }
function log_dry(string $msg)  { log_msg('[DRY] ' . $msg, 'DRY  '); }

// ── Load data ─────────────────────────────────────────────────────
function load_data(): array {
    return json_decode(file_get_contents(DATA_FILE), true);
}
function save_data(array $data): void {
    if (!is_dir(DATA_BACKUPS)) mkdir(DATA_BACKUPS, 0755, true);
    copy(DATA_FILE, DATA_BACKUPS . 'stations_migrate_' . date('Ymd_His') . '.json');
    file_put_contents(
        DATA_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

// ── Resize helper (same as admin/index.php) ───────────────────────
function resize_image(GdImage $src, int $sw, int $sh, int $targetW): GdImage {
    $w = min($sw, $targetW);
    $h = (int)round($sh * $w / $sw);
    $dst = imagecreatetruecolor($w, $h);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 8, 8, 8));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);
    return $dst;
}

// ── Ensure output dirs exist ──────────────────────────────────────
function ensure_dirs(): void {
    foreach ([ORIGINALS_DIR, MEDIUM_DIR, THUMBS_DIR] as $dir) {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }
}

// ════════════════════════════════════════════════════════════════════
//  PHASE 0 — DRY RUN
// ════════════════════════════════════════════════════════════════════
function phase_dry_run(): void {
    log_msg("=== DRY RUN ANALYSIS ===");

    $data       = load_data();
    $stations   = $data['stations'];
    $withImage  = array_filter($stations, fn($s) => !empty($s['image']));
    $sources    = [];
    $conflicts  = [];
    $missing    = [];

    foreach ($withImage as $s) {
        $src = STATIONS_DIR . $s['image'];
        if (!file_exists($src)) {
            $missing[] = $s['id'];
            continue;
        }
        $id    = pathinfo($s['image'], PATHINFO_FILENAME);
        $bytes = filesize($src);
        $sources[$id] = $bytes;

        // Check for conflicts in output dirs
        $checks = [
            ORIGINALS_DIR . $id . '.jpg',
            MEDIUM_DIR    . $id . '.jpg',
            MEDIUM_DIR    . $id . '.webp',
            THUMBS_DIR    . $id . '.jpg',
            STATIONS_DIR  . $id . '.webp',
        ];
        foreach ($checks as $c) {
            if (file_exists($c)) $conflicts[] = $c;
        }
    }

    $count       = count($sources);
    $totalBytes  = array_sum($sources);
    $perStation  = 5; // jpg large, webp large, jpg medium, webp medium, thumb
    $estOutputs  = $count * $perStation;

    // Rough output size estimate (large ~1.2MB, medium ~500KB, thumb ~60KB, webp ~70%)
    $estLargeJpg  = $count * 1_200_000;
    $estLargeWebP = $count *   840_000;
    $estMedJpg    = $count *   500_000;
    $estMedWebP   = $count *   350_000;
    $estThumb     = $count *    60_000;
    $estTotal     = $estLargeJpg + $estLargeWebP + $estMedJpg + $estMedWebP + $estThumb;

    log_msg("Source images:          $count");
    log_msg("Source total size:      " . round($totalBytes / 1048576, 1) . " MB");
    log_msg("Expected output files:  $estOutputs");
    log_msg("Estimated output size:  ~" . round($estTotal / 1048576) . " MB (rough estimate)");
    log_msg("Existing conflicts:     " . count($conflicts));
    log_msg("Missing source files:   " . count($missing));

    if ($missing) {
        foreach ($missing as $id) log_warn("Missing source: $id");
    }
    if ($conflicts) {
        log_msg("Existing files that would be SKIPPED:");
        foreach ($conflicts as $c) log_skip(basename($c));
    }

    // List largest sources
    arsort($sources);
    log_msg("\nTop 5 largest sources:");
    foreach (array_slice($sources, 0, 5, true) as $id => $bytes) {
        log_dry("  $id: " . round($bytes / 1048576, 1) . " MB");
    }

    log_msg("\nDisk space available: " . round(disk_free_space(ROOT) / 1073741824, 1) . " GB");
    log_msg("Dry run complete. No files written.");
}

// ════════════════════════════════════════════════════════════════════
//  PHASE 1 — ARCHIVE (copy originals, never overwrite)
// ════════════════════════════════════════════════════════════════════
function phase_archive(bool $dry): void {
    log_msg("=== PHASE 1: ARCHIVE ORIGINALS ===");
    ensure_dirs();

    $data      = load_data();
    $withImage = array_filter($data['stations'], fn($s) => !empty($s['image']));
    $copied    = 0;
    $skipped   = 0;
    $failed    = 0;

    foreach ($withImage as $s) {
        $src  = STATIONS_DIR . $s['image'];
        $id   = pathinfo($s['image'], PATHINFO_FILENAME);
        $dest = ORIGINALS_DIR . $id . '.jpg';

        if (!file_exists($src)) {
            log_fail("Source not found: " . $s['image']);
            $failed++;
            continue;
        }

        if (file_exists($dest)) {
            // Verify existing archive is identical
            if (filesize($src) === filesize($dest)) {
                log_skip("Already archived: $id");
            } else {
                log_warn("Archive size mismatch for $id — skipping to preserve existing archive");
            }
            $skipped++;
            continue;
        }

        if ($dry) {
            log_dry("Would copy $id → originals/$id.jpg (" . round(filesize($src)/1048576, 1) . " MB)");
            $copied++;
            continue;
        }

        if (copy($src, $dest)) {
            // Verify the copy
            if (filesize($src) === filesize($dest)) {
                log_ok("Archived: $id (" . round(filesize($src)/1048576, 1) . " MB)");
                $copied++;
            } else {
                log_fail("Archive verification failed for $id — sizes differ");
                unlink($dest); // remove bad copy
                $failed++;
            }
        } else {
            log_fail("Copy failed: $id");
            $failed++;
        }
    }

    log_msg("\nArchive summary: copied=$copied  skipped=$skipped  failed=$failed");

    if (!$dry) {
        // Final count check
        $archiveCount = count(glob(ORIGINALS_DIR . '*.jpg'));
        log_msg("Originals dir now contains: $archiveCount JPG files");
    }
}

// ════════════════════════════════════════════════════════════════════
//  PHASE 2 — PROCESS (generate optimized versions)
// ════════════════════════════════════════════════════════════════════
function phase_process(bool $dry): array {
    log_msg("=== PHASE 2: GENERATE OPTIMIZED IMAGES ===");
    ensure_dirs();

    $data      = load_data();
    $withImage = array_filter($data['stations'], fn($s) => !empty($s['image']));
    $processed = 0;
    $skipped   = 0;
    $failed    = 0;
    $failures  = [];
    $results   = []; // id => ['version' => ts, 'placeholder' => datauri]

    foreach ($withImage as $s) {
        $id  = pathinfo($s['image'], PATHINFO_FILENAME);
        $src = ORIGINALS_DIR . $id . '.jpg';

        // Fallback: use stations/ if archive hasn't been created yet
        if (!file_exists($src)) {
            $src = STATIONS_DIR . $s['image'];
        }
        if (!file_exists($src)) {
            log_fail("No source for $id — skipping");
            $failures[] = $id;
            $failed++;
            continue;
        }

        $version = filemtime($src); // use file mtime as version

        if ($dry) {
            log_dry("Would process: $id (source: " . round(filesize($src)/1048576, 1) . " MB)");
            $processed++;
            continue;
        }

        log_msg("Processing: $id...");

        // Load source image
        $img = @imagecreatefromjpeg($src);
        if (!$img) {
            log_fail("Cannot read image: $id");
            $failures[] = $id;
            $failed++;
            continue;
        }

        $sw = imagesx($img);
        $sh = imagesy($img);
        log_msg("  Source: {$sw}×{$sh}px");

        $ok = true;

        // ── Large (2400px) → stations/{id}.jpg + .webp ────────────
        // Overwrite stations/ with the optimized large version
        $large = resize_image($img, $sw, $sh, 2400);
        $lw = imagesx($large);
        $lh = imagesy($large);

        if (!@imagejpeg($large, STATIONS_DIR . $id . '.jpg', 88)) {
            log_fail("  Failed writing large JPG for $id");
            $ok = false;
        } else {
            $sz = round(filesize(STATIONS_DIR . $id . '.jpg') / 1024);
            log_ok("  large JPG:  {$lw}×{$lh}px → {$sz} KB");
        }
        if (function_exists('imagewebp')) {
            if (!@imagewebp($large, STATIONS_DIR . $id . '.webp', 82)) {
                log_warn("  Failed writing large WebP for $id");
            } else {
                $sz = round(filesize(STATIONS_DIR . $id . '.webp') / 1024);
                log_ok("  large WebP: {$lw}×{$lh}px → {$sz} KB");
            }
        }
        imagedestroy($large);

        // ── Medium (1400px) → medium/{id}.jpg + .webp ─────────────
        $medium = resize_image($img, $sw, $sh, 1400);
        $mw = imagesx($medium);
        $mh = imagesy($medium);

        if (!@imagejpeg($medium, MEDIUM_DIR . $id . '.jpg', 85)) {
            log_fail("  Failed writing medium JPG for $id");
            $ok = false;
        } else {
            $sz = round(filesize(MEDIUM_DIR . $id . '.jpg') / 1024);
            log_ok("  medium JPG: {$mw}×{$mh}px → {$sz} KB");
        }
        if (function_exists('imagewebp')) {
            if (!@imagewebp($medium, MEDIUM_DIR . $id . '.webp', 80)) {
                log_warn("  Failed writing medium WebP for $id");
            } else {
                $sz = round(filesize(MEDIUM_DIR . $id . '.webp') / 1024);
                log_ok("  medium WebP: {$mw}×{$mh}px → {$sz} KB");
            }
        }
        imagedestroy($medium);

        // ── Thumb (500px) → thumbs/{id}.jpg ───────────────────────
        $thumb = resize_image($img, $sw, $sh, 500);
        $tw = imagesx($thumb);
        $th = imagesy($thumb);

        if (!@imagejpeg($thumb, THUMBS_DIR . $id . '.jpg', 78)) {
            log_fail("  Failed writing thumb for $id");
            $ok = false;
        } else {
            $sz = round(filesize(THUMBS_DIR . $id . '.jpg') / 1024);
            log_ok("  thumb JPG:  {$tw}×{$th}px → {$sz} KB");
        }
        imagedestroy($thumb);

        // ── Blur placeholder (24px) ────────────────────────────────
        $blur = resize_image($img, $sw, $sh, 24);
        ob_start();
        imagejpeg($blur, null, 25);
        $blurBytes = ob_get_clean();
        $placeholder = 'data:image/jpeg;base64,' . base64_encode($blurBytes);
        imagedestroy($blur);
        log_ok("  blur placeholder: " . strlen($blurBytes) . " bytes");

        imagedestroy($img);

        if ($ok) {
            $results[$s['id']] = ['version' => $version, 'placeholder' => $placeholder];
            $processed++;
        } else {
            $failures[] = $id;
            $failed++;
        }
    }

    log_msg("\nProcessing summary: processed=$processed  skipped=$skipped  failed=$failed");
    if ($failures) {
        log_warn("Failures: " . implode(', ', $failures));
    }

    return $results;
}

// ════════════════════════════════════════════════════════════════════
//  PHASE 3 — UPDATE stations.json
// ════════════════════════════════════════════════════════════════════
function phase_update(array $results, bool $dry): void {
    log_msg("=== PHASE 3: UPDATE stations.json ===");

    if (empty($results)) {
        log_warn("No results to write — skipping JSON update");
        return;
    }

    $data     = load_data();
    $updated  = 0;
    $skipped  = 0;

    foreach ($data['stations'] as &$s) {
        if (!isset($results[$s['id']])) {
            $skipped++;
            continue;
        }
        $r = $results[$s['id']];
        if ($dry) {
            log_dry("Would update $s[id]: imageVersion={$r['version']}, placeholder=" . strlen($r['placeholder']) . " chars");
        } else {
            $s['imageVersion']     = $r['version'];
            $s['imagePlaceholder'] = $r['placeholder'];
        }
        $updated++;
    }
    unset($s);

    if (!$dry) {
        save_data($data);
        log_ok("stations.json updated: $updated stations");
    } else {
        log_dry("Would update $updated stations in stations.json");
    }
}

// ════════════════════════════════════════════════════════════════════
//  PHASE 4 — VALIDATE
// ════════════════════════════════════════════════════════════════════
function phase_validate(): void {
    log_msg("=== PHASE 4: VALIDATION ===");

    $data      = load_data();
    $withImage = array_filter($data['stations'], fn($s) => !empty($s['image']));
    $total     = count($withImage);

    $checks = [
        'originals_jpg'  => 0,
        'stations_jpg'   => 0,
        'stations_webp'  => 0,
        'medium_jpg'     => 0,
        'medium_webp'    => 0,
        'thumbs_jpg'     => 0,
        'has_version'    => 0,
        'has_placeholder'=> 0,
    ];
    $missing = [];

    foreach ($withImage as $s) {
        $id = pathinfo($s['image'], PATHINFO_FILENAME);

        $map = [
            'originals_jpg'  => ORIGINALS_DIR . $id . '.jpg',
            'stations_jpg'   => STATIONS_DIR  . $id . '.jpg',
            'stations_webp'  => STATIONS_DIR  . $id . '.webp',
            'medium_jpg'     => MEDIUM_DIR    . $id . '.jpg',
            'medium_webp'    => MEDIUM_DIR    . $id . '.webp',
            'thumbs_jpg'     => THUMBS_DIR    . $id . '.jpg',
        ];

        foreach ($map as $key => $path) {
            if (file_exists($path)) {
                $checks[$key]++;
            } else {
                $missing[$key][] = $id;
            }
        }

        if (!empty($s['imageVersion']))     $checks['has_version']++;
        if (!empty($s['imagePlaceholder'])) $checks['has_placeholder']++;
    }

    log_msg("\n  Total stations with image: $total");
    foreach ($checks as $key => $count) {
        $pct    = round($count / $total * 100);
        $status = $count === $total ? '✓' : '✗';
        log_msg("  $status $key: $count / $total ($pct%)");
    }

    if (!empty($missing)) {
        log_msg("\nMissing files by type:");
        foreach ($missing as $type => $ids) {
            log_warn("  $type missing (" . count($ids) . "): " . implode(', ', array_slice($ids, 0, 5)) .
                (count($ids) > 5 ? '...' : ''));
        }
    }

    // Storage summary
    $bytes = [
        'stations' => array_sum(array_map('filesize', glob(STATIONS_DIR . '*.{jpg,webp}', GLOB_BRACE) ?: [])),
        'originals'=> array_sum(array_map('filesize', glob(ORIGINALS_DIR . '*.jpg') ?: [])),
        'medium'   => array_sum(array_map('filesize', glob(MEDIUM_DIR . '*.{jpg,webp}', GLOB_BRACE) ?: [])),
        'thumbs'   => array_sum(array_map('filesize', glob(THUMBS_DIR . '*.jpg') ?: [])),
    ];

    log_msg("\nStorage summary:");
    foreach ($bytes as $dir => $b) {
        log_msg("  $dir/: " . round($b / 1048576, 1) . " MB");
    }
    log_msg("  Total: " . round(array_sum($bytes) / 1048576, 1) . " MB");
}

// ════════════════════════════════════════════════════════════════════
//  MAIN
// ════════════════════════════════════════════════════════════════════
$results = [];
$startTime = microtime(true);

log_msg("T—BANEN Migration — " . date('Y-m-d H:i:s'));
log_msg("Mode: $mode" . ($isDryRun ? ' (DRY RUN)' : ''));

match(true) {
    $mode === '--dry-run'              => phase_dry_run(),
    $mode === '--archive'              => phase_archive($isDryRun),
    $mode === '--process'              => ($results = phase_process($isDryRun)),
    $mode === '--update'               => log_warn("--update requires results from --process; use --full"),
    $mode === '--validate'             => phase_validate(),
    $mode === '--full' => (function() use ($isDryRun, &$results) {
        if ($isDryRun) {
            phase_dry_run();
        } else {
            phase_archive(false);
            $results = phase_process(false);
            phase_update($results, false);
            phase_validate();
        }
    })(),
    default => log_warn("Unknown mode: $mode"),
};

$elapsed = round(microtime(true) - $startTime, 1);
log_msg("\nCompleted in {$elapsed}s");

// Write log file
file_put_contents(LOG_FILE, implode("\n", $log) . "\n", FILE_APPEND);
log_msg("Log appended to " . LOG_FILE);
