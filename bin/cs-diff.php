<?php
declare(strict_types=1);

/**
 * cs-diff — gate anti-regresión de phpcs por LÍNEAS nuevas.
 *
 * Corre phpcs únicamente sobre las líneas añadidas/modificadas respecto a una
 * base y falla (exit 1) si esas líneas introducen violaciones. Ignora la deuda
 * pre-existente en líneas no tocadas: solo bloquea lo NUEVO, para que el
 * conteo de novedades no vuelva a crecer mientras se drena por olas.
 *
 * Uso:
 *   php bin/cs-diff.php             # cambios staged (git diff --cached) → pre-commit
 *   php bin/cs-diff.php <base_ref>  # <base_ref>...HEAD → CI / revisión manual
 *
 * Solo considera archivos .php bajo src/ y tests/ (el scope de phpcs.xml).
 */

$base = $argv[1] ?? null;

$diffCmd = $base === null
    ? 'git diff --cached --unified=0 --diff-filter=ACM -- "*.php"'
    : sprintf('git diff --unified=0 --diff-filter=ACM %s...HEAD -- "*.php"', escapeshellarg($base));

$diff = shell_exec($diffCmd);
if (!is_string($diff) || trim($diff) === '') {
    echo "cs-diff: sin cambios .php que revisar.\n";
    exit(0);
}

// Parsear el unified diff: por archivo, el conjunto de líneas añadidas (lado nuevo).
$addedLines = [];
$current = null;
foreach (explode("\n", $diff) as $line) {
    if (preg_match('#^\+\+\+ b/(.+)$#', $line, $m)) {
        $current = str_replace('\\', '/', trim($m[1]));
        $addedLines[$current] = [];
    } elseif ($current !== null && preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $m)) {
        $start = (int)$m[1];
        $count = isset($m[2]) ? (int)$m[2] : 1;
        for ($i = 0; $i < $count; $i++) {
            $addedLines[$current][$start + $i] = true;
        }
    }
}

// Filtrar al scope de phpcs (src/ y tests/) y a archivos que aún existen.
$files = [];
foreach ($addedLines as $path => $lines) {
    if ($lines === [] || !preg_match('#^(src|tests)/#', $path) || !is_file($path)) {
        continue;
    }
    $files[$path] = $lines;
}
if ($files === []) {
    echo "cs-diff: sin líneas nuevas en src/ o tests/.\n";
    exit(0);
}

// Correr phpcs (JSON) sobre esos archivos. `php vendor/bin/phpcs` por portabilidad Win/Linux.
$phpcs = __DIR__ . '/../vendor/bin/phpcs';
$fileArgs = implode(' ', array_map('escapeshellarg', array_keys($files)));
$json = shell_exec(sprintf('php %s --report=json %s 2>%s', escapeshellarg($phpcs), $fileArgs, PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
$report = json_decode((string)$json, true);
if (!is_array($report) || empty($report['files'])) {
    echo "cs-diff: OK (sin violaciones en los archivos revisados).\n";
    exit(0);
}

// Retener solo las violaciones cuyas líneas fueron añadidas/modificadas.
$violations = [];
foreach ($report['files'] as $path => $data) {
    $norm = str_replace('\\', '/', (string)$path);
    $rel = null;
    foreach (array_keys($files) as $f) {
        if ($norm === $f || str_ends_with($norm, '/' . $f)) {
            $rel = $f;
            break;
        }
    }
    if ($rel === null) {
        continue;
    }
    foreach ($data['messages'] ?? [] as $msg) {
        if (!empty($files[$rel][$msg['line']])) {
            $violations[] = sprintf('  %s:%d  %s  (%s)', $rel, $msg['line'], $msg['message'], $msg['source']);
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "\ncs-diff: se introdujeron violaciones NUEVAS de phpcs en líneas modificadas:\n\n");
    fwrite(STDERR, implode("\n", $violations) . "\n\n");
    fwrite(STDERR, "Corrige esas líneas (o corre 'composer cs-fix' para el formato) antes de commitear.\n\n");
    exit(1);
}

echo "cs-diff: OK (líneas nuevas sin violaciones).\n";
exit(0);
