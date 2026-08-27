<?php

/**
 * GAP-08 — Barrido forense de las páginas Vue.
 *
 * La lógica de análisis vive en App\Support\FrontendAuditor, que tiene tests
 * propios (tests/Feature/FrontendAuditorTest.php). Ese reparto es deliberado:
 * la primera versión de esta herramienta era un script suelto y produjo diez
 * falsos positivos por tres bugs de regex distintos, pero se usó igualmente
 * para afirmar cosas sobre el estado del frontend. Una herramienta que se
 * equivoca en silencio da confianza injustificada.
 *
 *   php scripts/frontend-audit.php
 */

require __DIR__.'/../vendor/autoload.php';

use App\Support\FrontendAuditor;

$root = __DIR__.'/../resources/js/Pages';
$auditor = new FrontendAuditor();
$rows = [];

$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), '.vue')) {
        $files[] = str_replace('\\', '/', $file->getPathname());
    }
}
sort($files);

foreach ($files as $path) {
    $page = substr(str_replace('\\', '/', $path), strpos(str_replace('\\', '/', $path), 'Pages/') + 6);
    $rows[] = ['page' => $page] + $auditor->analyze((string) file_get_contents($path));
}

$mutating = array_values(array_filter($rows, fn ($r) => $r['actions'] !== []));
$readonly = array_values(array_filter($rows, fn ($r) => $r['actions'] === []));

echo 'TOTAL PAGINAS: '.count($rows)."\n";
echo 'CON ACCIONES MUTANTES: '.count($mutating)."\n";
echo 'SOLO LECTURA: '.count($readonly)."\n\n";

echo "=== PAGINAS CON ACCIONES MUTANTES ===\n";
printf("%-44s %-7s %-9s %-7s %-6s %s\n", 'PAGINA', 'GUARD', 'DISABLED', 'ERROR', 'MODAL', 'ACCIONES');
foreach ($mutating as $r) {
    printf("%-44s %-7s %-9s %-7s %-6s %s\n",
        substr($r['page'], 0, 43),
        $r['guard'] ? 'si' : '** NO',
        $r['disabled'] ? 'si' : 'no',
        $r['error_handling'] ? 'si' : '** NO',
        $r['modal'] ? 'si' : '-',
        implode(' | ', array_slice($r['actions'], 0, 3))
    );
}

echo "\n=== PROBLEMAS DETECTADOS ===\n";
$issues = 0;
foreach ($mutating as $r) {
    $problems = [];
    if (!$r['guard']) {
        $problems[] = 'SIN guard de doble envio';
    }
    if (!$r['error_handling']) {
        $problems[] = 'SIN manejo de error';
    }
    if ($r['native_dialog']) {
        $problems[] = 'DIALOGO NATIVO confirm/prompt';
    }
    if ($problems) {
        $issues++;
        echo '  '.$r['page'].': '.implode('; ', $problems)."\n";
    }
}
foreach ($rows as $r) {
    if ($r['actions'] === [] && !$r['empty_state'] && preg_match('/Index|List/i', $r['page'])) {
        $issues++;
        echo '  '.$r['page'].": listado SIN estado vacio aparente\n";
    }
}
if (!$issues) {
    echo "  ninguno\n";
}

echo "\n=== PAGINAS DE SOLO LECTURA ===\n";
foreach ($readonly as $r) {
    echo '  '.$r['page'].($r['empty_state'] ? ' (estado vacio: si)' : '')."\n";
}

exit($issues > 0 ? 1 : 0);
