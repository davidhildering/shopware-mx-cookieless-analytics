<?php declare(strict_types=1);

/**
 * Dependency-free smoke test for the plugin: `php tests/smoke.php` (also via
 * `composer smoke`).
 *
 * Why this exists: the plugin is PHP in a TypeScript monorepo with no PHPUnit
 * harness, and the Shopware store review's static analysis only runs after
 * submission. Two real regressions slipped through exactly that gap:
 *  - `CacheClearer::clearCache()`, a method Shopware does not have (fatal
 *    Error on the first successful connect);
 *  - a preg_match pattern whose `#` delimiter also appeared inside the
 *    pattern, which made the one-click start endpoint throw.
 * Both are cheap to catch without a framework, so they are checked here.
 */

$root = dirname(__DIR__) . '/src';
$failures = [];

/** Collect every regex literal used in a preg_* call and compile it. */
function checkRegexes(string $dir): array
{
    $bad = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (!preg_match_all('/preg_[a-z_]+\(\s*(["\'])(.+?)\1/s', $source, $hits, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($hits as $hit) {
            $pattern = $hit[2];
            // Only look at things that actually look like a pattern (a
            // delimiter at the start), not variable-driven calls.
            if (!preg_match('~^([^\w\s]).*?\1[a-z]*$~s', $pattern)) {
                continue;
            }
            $result = @preg_match($pattern, '');
            if ($result === false || preg_last_error() !== PREG_NO_ERROR) {
                $bad[] = sprintf(
                    '%s: invalid pattern %s (%s)',
                    basename($file->getPathname()),
                    $pattern,
                    preg_last_error_msg(),
                );
            }
        }
    }

    return $bad;
}

require __DIR__ . '/../src/Service/ConnectService.php';

$failures = array_merge($failures, checkRegexes($root));

/** apiBase validation: an unusable origin must be rejected, not "fixed". */
$apiBaseCases = [
    'https://app.metrixs.eu' => 'https://app.metrixs.eu',
    'https://app.metrixs.eu/' => 'https://app.metrixs.eu',
    '  http://localhost:8080  ' => 'http://localhost:8080',
    'HTTPS://APP.METRIXS.EU' => 'https://app.metrixs.eu',
    '' => null,
    '/' => null,
    'app.metrixs.eu' => null, // no scheme → relative URLs downstream
    'https://app.metrixs.eu/api' => null, // path would double up
    'https://app.metrixs.eu?x=1' => null,
    'https://user:pass@metrixs.eu' => null,
    'ftp://app.metrixs.eu' => null,
    'https://met rixs.eu' => null,
];
foreach ($apiBaseCases as $input => $expected) {
    $actual = \Mxcoan\Service\ConnectService::normalizeApiBase((string) $input);
    if ($actual !== $expected) {
        $failures[] = sprintf(
            'normalizeApiBase(%s): expected %s, got %s',
            var_export($input, true),
            var_export($expected, true),
            var_export($actual, true),
        );
    }
}

/** Shopware has no CacheClearer::clearCache() in any supported version, yet
 *  calling it is fatal exactly when a connect SUCCEEDS. Guard the name. */
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    if (str_contains((string) file_get_contents($file->getPathname()), '->clearCache(')) {
        $failures[] = sprintf('%s calls CacheClearer::clearCache() (does not exist in Shopware)', $file->getFilename());
    }
}

if ($failures === []) {
    echo "OK: plugin smoke tests passed\n";
    exit(0);
}

fwrite(STDERR, "FAIL:\n - " . implode("\n - ", $failures) . "\n");
exit(1);
