<?php
/**
 * Genera reglas/deprecados.json: métodos y clases marcados @deprecated en el código REAL de
 * PrestaShop (carpeta classes/), con la primera versión en que aparecen marcados.
 *
 * El oficial avisa (nota) «Method 'Module::isEnabled()' is deprecated from PrestaShop 9.0.0»:
 * Module::isEnabled lleva @deprecated en 9.x y no en 8.2.7 -> «desde 9.0.0». Por eso, cuando la
 * marca aparece al cambiar de versión mayor, se da la x.0.0 de esa versión.
 *
 * Uso: php herramientas/generar_deprecados.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

$base = 'C:/dev-tools/prestashop';
$versiones = array('1.7.8.11', '8.1.7', '8.2.7', '9.1.5', '9.2.0');
$parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
$finder = new NodeFinder();

function vlMayor(string $v): string
{
    $p = explode('.', $v);

    return $p[0] === '1' ? $p[0] . '.' . $p[1] : $p[0];
}

$porVersion = array();
foreach ($versiones as $v) {
    $dir = $base . '/' . $v . '/extracted/classes';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    $marcas = array();
    foreach ($it as $f) {
        if (strtolower($f->getExtension()) !== 'php') {
            continue;
        }
        try {
            $ast = $parser->parse(file_get_contents($f->getPathname()));
        } catch (Throwable $e) {
            continue;
        }
        foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $c) {
            if (!$c->name) {
                continue;
            }
            $clase = preg_replace('#Core$#', '', $c->name->toString());
            $doc = $c->getDocComment();
            if ($doc && stripos($doc->getText(), '@deprecated') !== false) {
                $marcas[strtolower($clase)] = array('tipo' => 'clase', 'nombre' => $clase);
            }
            foreach ($c->getMethods() as $mt) {
                $d = $mt->getDocComment();
                if ($d && stripos($d->getText(), '@deprecated') !== false) {
                    $k = strtolower($clase . '::' . $mt->name->toString());
                    $marcas[$k] = array('tipo' => 'metodo', 'nombre' => $clase . '::' . $mt->name->toString() . '()', 'static' => $mt->isStatic());
                }
            }
        }
    }
    $porVersion[$v] = $marcas;
    fwrite(STDERR, $v . ': ' . count($marcas) . " marcas\n");
}

$salida = array();
foreach ($versiones as $i => $v) {
    foreach ($porVersion[$v] as $k => $info) {
        if (isset($salida[$k])) {
            $salida[$k]['en'][] = $v;
            continue;
        }
        $info['en'] = array($v);
        $desde = $v;
        if ($i > 0 && vlMayor($versiones[$i - 1]) !== vlMayor($v)) {
            $desde = vlMayor($v) . '.0.0';
        }
        $info['desde'] = $desde;
        $salida[$k] = $info;
    }
}
ksort($salida);
file_put_contents(dirname(__DIR__) . '/reglas/deprecados.json', json_encode(array(
    'generado' => date('Y-m-d'),
    'fuente' => 'C:/dev-tools/prestashop/<version>/extracted/classes (@deprecated en docblocks)',
    'versiones' => $versiones,
    'marcas' => $salida,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo count($salida) . " marcas. Module::isEnabled -> " . json_encode($salida['module::isenabled'] ?? null) . "\n";
