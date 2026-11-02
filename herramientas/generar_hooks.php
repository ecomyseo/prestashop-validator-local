<?php
/**
 * Genera reglas/hooks.json a partir del código REAL de PrestaShop en C:\dev-tools\prestashop.
 *
 * - deprecated: Hook::$deprecated_hooks de cada versión (con su "from").
 * - removed_in_9: hooks que existen en 8.2.x (hook.xml + alias) y ya no existen en 9.0+.
 * - known: todos los hooks conocidos por versión (para avisar de nombres inexistentes).
 *
 * Uso: php herramientas/generar_hooks.php
 */
$base = 'C:/dev-tools/prestashop';
$versiones = array('1.7.8.11', '8.2.7', '9.1.5', '9.2.0');

function vlHooksDe($dir)
{
    $hooks = array();
    foreach (array('install', 'install-dev') as $inst) {
        $xml = $dir . '/' . $inst . '/data/xml/hook.xml';
        if (is_file($xml)) {
            preg_match_all('#<name>([^<]+)</name>#', file_get_contents($xml), $m);
            foreach ($m[1] as $h) {
                $hooks[strtolower(trim($h))] = trim($h);
            }
        }
        $alias = $dir . '/' . $inst . '/data/xml/hook_alias.xml';
        if (is_file($alias)) {
            preg_match_all('#<alias>([^<]+)</alias>#', file_get_contents($alias), $m);
            foreach ($m[1] as $h) {
                $hooks[strtolower(trim($h))] = trim($h);
            }
            preg_match_all('#<name>([^<]+)</name>#', file_get_contents($alias), $m);
            foreach ($m[1] as $h) {
                $hooks[strtolower(trim($h))] = trim($h);
            }
        }
    }
    // Alias declarados en código (Hook::$hook_alias / getHookAliasesList legacy)
    $hookPhp = $dir . '/classes/Hook.php';
    if (is_file($hookPhp)) {
        $src = file_get_contents($hookPhp);
        if (preg_match('#deprecated_hooks\s*=\s*\[(.*?)\];#s', $src, $mm)) {
            preg_match_all("#'([A-Za-z0-9_]+)'\s*=>#", $mm[1], $m2);
            foreach ($m2[1] as $h) {
                $hooks[strtolower($h)] = $h;
            }
        }
    }

    return $hooks;
}

function vlDeprecados($dir)
{
    $out = array();
    $hookPhp = $dir . '/classes/Hook.php';
    if (!is_file($hookPhp)) {
        return $out;
    }
    $src = file_get_contents($hookPhp);
    if (preg_match('#deprecated_hooks\s*=\s*\[(.*?)\];#s', $src, $mm)) {
        preg_match_all("#'([A-Za-z0-9_]+)'\s*=>\s*\['from'\s*=>\s*'([0-9.]+)'\]#", $mm[1], $m2, PREG_SET_ORDER);
        foreach ($m2 as $x) {
            $out[$x[1]] = $x[2];
        }
    }

    return $out;
}

$known = array();
$deprecated = array();
foreach ($versiones as $v) {
    $dir = $base . '/' . $v . '/extracted';
    $known[$v] = vlHooksDe($dir);
    $deprecated[$v] = vlDeprecados($dir);
    fwrite(STDERR, $v . ': ' . count($known[$v]) . " hooks, " . count($deprecated[$v]) . " deprecados\n");
}

$removed9 = array();
foreach ($known['8.2.7'] as $lc => $nombre) {
    if (!isset($known['9.1.5'][$lc]) && !isset($known['9.2.0'][$lc])) {
        $removed9[$lc] = $nombre;
    }
}
// Documentados en devdocs.prestashop-project.org/9/modules/core-updates/9.0/ (hooks dinámicos
// de la ficha de producto legacy, que no figuran en hook.xml).
// (Los genéricos actionAdminDeleteBefore & co. NO: AdminController los sigue lanzando en 9.x
// para el resto de controladores legacy; solo desaparecen en la ficha de producto.)
foreach (array(
    'actionAdminProductsControllerActivateAfter', 'actionAdminProductsControllerActivateBefore',
    'actionAdminProductsControllerDeactivateAfter', 'actionAdminProductsControllerDeactivateBefore',
    'actionAdminProductsControllerDeleteAfter', 'actionAdminProductsControllerDeleteBefore',
    'actionAdminProductsControllerDuplicateAfter', 'actionAdminProductsControllerDuplicateBefore',
    'actionAdminProductsControllerSortAfter', 'actionAdminProductsControllerSortBefore',
) as $h) {
    $removed9[strtolower($h)] = $h;
}
$removed9 = array_values($removed9);
sort($removed9, SORT_STRING | SORT_FLAG_CASE);

$salida = array(
    'generado' => date('Y-m-d'),
    'fuente' => 'C:/dev-tools/prestashop/<version>/extracted (hook.xml, hook_alias.xml, classes/Hook.php)',
    'deprecated' => $deprecated,
    'removed_in_9' => $removed9,
    'known' => array_map('array_values', $known),
);
file_put_contents(dirname(__DIR__) . '/reglas/hooks.json', json_encode($salida, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo 'removed_in_9: ' . count($removed9) . "\n" . implode(', ', $removed9) . "\n";
