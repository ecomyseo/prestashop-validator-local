<?php
/**
 * Repone fallos en una copia de lib/VlTras100.php y exige que pruebas/tras100.php los cace.
 *
 *   php pruebas/tras100_mutaciones.php
 *
 * Termina con «CAZADAS n de N». Una mutación que no se aplica (la cadena no casa) es un fallo del
 * guion, no «una menos». Código de salida 0 solo si se cazan todas.
 */
set_time_limit(0);
$raiz = str_replace('\\', '/', dirname(__DIR__));
$fuente = (string) file_get_contents($raiz . '/lib/VlTras100.php');
$M = array(
    'los pasos de después de un fallo se ejecutan' => array('                if ($roto !== null) {', '                if (false) {'),
    'no se mira «FALLAN: 0»' => array("if (\$fallo === null && !empty(\$pc['exige'])", "if (false && !empty(\$pc['exige'])"),
    'un fichero viejo cuenta como generado' => array('if (!is_file($ruta) || filemtime($ruta) < $t0 - 2) {', 'if (!is_file($ruta)) {'),
    'capturas viejas cuentan como hechas' => array('return filemtime($f) >= $t0 - 2;', 'return true;'),
    'PDF sin contar imágenes' => array('if ($n < $caps) {', 'if (false) {'),
    'para también lo que ya estaba' => array('            if (in_array($puerto, $antes, true)) {', '            if (false) {'),
    'no se quita el bloque anterior de capturas' => array("'.*?' . preg_quote(self::BLOQUE_FIN, '#')", "'NOEXISTE' . preg_quote(self::BLOQUE_FIN, '#')"),
    'repite capturas ya citadas' => array('return !in_array(strtolower(basename($f)), $citadas, true);', 'return true;'),
    'capturas sin instalar' => array('                        $quiero[$r] = true;', '                        unset($r);'),
    'proceso muerto sigue «en curso»' => array("\$e['estado'] = 'interrumpido';", "\$e['estado'] = \$e['estado'];"),
    'sin límite de tiempo' => array('if (time() - $t0 > $limite) {', 'if (false) {'),
    'no para si algo falló' => array("                if (!empty(\$pc['siempre'])) {\n                    try {", "                if (!empty(\$pc['siempre']) && \$roto === null) {\n                    try {"),
);
$tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/vl_t1mut_' . getmypid();
@mkdir($tmp, 0777, true);
$cazadas = 0;
$noAplica = 0;
foreach ($M as $nombre => $par) {
    list($de, $a) = $par;
    $variantes = array($de => $a);
    if (strpos($de, "\n") !== false) {
        $variantes[str_replace("\n", "\r\n", $de)] = str_replace("\n", "\r\n", $a);
    }
    $mut = null;
    foreach ($variantes as $d => $x) {
        if (substr_count($fuente, $d) === 1) {
            $mut = str_replace($d, $x, $fuente);
        }
    }
    if ($mut === null) {
        ++$noAplica;
        echo '  [NO APLICA] ' . $nombre . PHP_EOL;
        continue;
    }
    $f = $tmp . '/VlTras100.php';
    file_put_contents($f, $mut);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($raiz . '/pruebas/tras100.php');
    putenv('VL_TRAS100_LIB=' . $f);
    exec($cmd . ' 2>&1', $salida, $rc);
    putenv('VL_TRAS100_LIB');
    $rojas = array_values(array_filter($salida, function ($l) {
        return strpos($l, '[XX]') !== false;
    }));
    if ($rc !== 0 && $rojas) {
        ++$cazadas;
        echo '  [CAZADA] ' . $nombre . ' <- ' . trim(str_replace('[XX]', '', $rojas[0])) . (count($rojas) > 1 ? ' (+' . (count($rojas) - 1) . ')' : '') . PHP_EOL;
    } else {
        echo '  [NO CAZADA] ' . $nombre . PHP_EOL;
    }
    $salida = array();
}
@unlink($tmp . '/VlTras100.php');
@rmdir($tmp);
$total = count($M);
echo 'CAZADAS: ' . $cazadas . ' de ' . $total . ($noAplica ? ' (NO APLICAN: ' . $noAplica . ')' : '') . PHP_EOL;
exit($cazadas === $total ? 0 : 1);
