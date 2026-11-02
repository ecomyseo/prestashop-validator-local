<?php
/**
 * Instala el skill «prestashop-validator» para Claude Code y Codex con las rutas de esta máquina.
 *
 *   php skill/instalar.php                  Claude Code (~/.claude/skills) y Codex (~/.codex/skills, si existe ~/.codex)
 *   php skill/instalar.php --claude         solo Claude Code
 *   php skill/instalar.php --codex          solo Codex
 *   php skill/instalar.php --destino=DIR    en DIR/prestashop-validator/SKILL.md (cualquier otra herramienta)
 *   php skill/instalar.php --php=RUTA       PHP que usará el skill (por defecto, el que ejecuta este script)
 *
 * Si ya hay un SKILL.md distinto, se guarda una copia SKILL.md.bak-<fecha> antes de escribir.
 */
$raiz = dirname(__DIR__);
$plantilla = __DIR__ . '/prestashop-validator/SKILL.md';
$php = PHP_BINARY;
$destinos = array();
$claude = false;
$codex = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--claude') {
        $claude = true;
    } elseif ($a === '--codex') {
        $codex = true;
    } elseif (strpos($a, '--destino=') === 0) {
        $destinos[] = substr($a, 10);
    } elseif (strpos($a, '--php=') === 0) {
        $php = substr($a, 6);
    } else {
        fwrite(STDERR, "Opción desconocida: $a\n");
        exit(2);
    }
}
$casa = getenv('USERPROFILE') ?: getenv('HOME');
if (!$destinos && !$claude && !$codex) {
    $claude = true;
    $codex = $casa && is_dir($casa . '/.codex');
}
if ($claude) {
    $destinos[] = $casa . '/.claude/skills';
}
if ($codex) {
    $destinos[] = $casa . '/.codex/skills';
}
if (!$destinos || !$casa && ($claude || $codex)) {
    fwrite(STDERR, "No se encuentra la carpeta del usuario (USERPROFILE / HOME). Usa --destino=DIR.\n");
    exit(2);
}
if (!is_file($php)) {
    fwrite(STDERR, "No existe el PHP indicado: $php\n");
    exit(2);
}

$texto = strtr(file_get_contents($plantilla), array(
    '{{VALIDATOR_DIR}}' => str_replace('\\', '/', realpath($raiz)),
    '{{PHP}}' => str_replace('\\', '/', realpath($php)),
));
if (strpos($texto, '{{') !== false) {
    fwrite(STDERR, "La plantilla tiene marcadores sin sustituir.\n");
    exit(1);
}

$fallos = 0;
foreach ($destinos as $d) {
    $dir = rtrim(str_replace('\\', '/', $d), '/') . '/prestashop-validator';
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        fwrite(STDERR, "No se puede crear $dir\n");
        ++$fallos;
        continue;
    }
    $fich = $dir . '/SKILL.md';
    if (is_file($fich) && file_get_contents($fich) !== $texto) {
        copy($fich, $fich . '.bak-' . date('Ymd-His'));
    }
    if (file_put_contents($fich, $texto) === false) {
        fwrite(STDERR, "No se puede escribir $fich\n");
        ++$fallos;
        continue;
    }
    echo 'Skill instalado: ' . $fich . PHP_EOL;
}
exit($fallos ? 1 : 0);
