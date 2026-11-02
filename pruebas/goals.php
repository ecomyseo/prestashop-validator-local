<?php
/**
 * BATERÍA DE GOALS — demuestra que cada verificación caza lo que tiene que cazar y nada más.
 *
 *   php pruebas/goals.php            todo (incluye PHPStan y PHP CS Fixer: ~1-2 min)
 *   php pruebas/goals.php --rapido   sin el módulo oficial de referencia
 *
 * 1. BASE LIMPIA: pruebas/base/vlgoalbase pasa el 100 % (0 errores, 0 avisos, 0 notas).
 * 2. CAZADAS: por cada goal, una copia de la base con UN fallo puesto a propósito; el goal
 *    tiene que saltar (y el script dice cuál ha saltado). "CAZADAS n de N" tiene que ser N de N.
 * 3. NO FALSOS POSITIVOS: casos que se parecen a un fallo y no lo son; no debe saltar nada.
 * 4. ORO: el módulo del generador oficial (oficial/generador/refstandard.zip) tiene que dar
 *    exactamente lo que dio validator.prestashop.com el 2026-11-02 (informe 3785333).
 * 5. COBERTURA: todo goal registrado en VlGoals tiene su caso.
 *
 * Código de salida 0 solo si todo está en verde.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('memory_limit', '-1');
set_time_limit(0);
$raiz = str_replace('\\', '/', dirname(__DIR__));
require $raiz . '/vendor/autoload.php';
foreach (glob($raiz . '/lib/*.php') as $f) {
    require_once $f;
}
$rapido = in_array('--rapido', $argv, true);
$base = $raiz . '/pruebas/base/vlgoalbase';
$tmpRaiz = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/vl_goals_' . getmypid();
@mkdir($tmpRaiz, 0777, true);
$validador = new VlValidador($raiz);

$CAB = "/**\n * Copyright since 2007 Ecom Experts\n *\n * NOTICE OF LICENSE\n *\n * This source file is subject to the Academic Free License version 3.0\n * that is bundled with this package in the file LICENSE.md.\n *\n * @author    Ecom Experts <ecomyseo@gmail.com>\n * @copyright Since 2007 Ecom Experts\n * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0\n */\n";
$PHP = "<?php\n\n" . $CAB . "if (!defined('_PS_VERSION_')) {\n    exit;\n}\n\n";
$HOOK = '        $this->context->smarty->assign(';
$codigo = function ($c) use ($HOOK) {
    return array('/vlgoalbase.php' => array($HOOK, $c . "\n" . $HOOK));
};
$TPL_VAR = "{\$vlgoalbase_title|escape:'htmlall':'UTF-8'}";

// goal => [paso(s) a ejecutar, cambios]
//   cambios: 'poner' => [ruta => contenido], 'quitar' => [rutas], 'cambiar' => [ruta => [buscar, poner]], 'zip' => 'tipo'
$casos = array(
    'REQ_MAIN_FILE' => array('requirements', array('quitar' => array('/vlgoalbase.php'))),
    'REQ_MAIN_CLASS' => array('requirements', array('cambiar' => array('/vlgoalbase.php' => array('class Vlgoalbase extends', 'class OtraClase extends')))),
    'REQ_EXTENDS' => array('requirements', array('cambiar' => array('/vlgoalbase.php' => array('extends Module', 'extends ObjectModel')))),
    'REQ_NAME' => array('requirements', array('cambiar' => array('/vlgoalbase.php' => array("\$this->name = 'vlgoalbase';", "\$this->name = 'VlGoalBase';")))),
    'REQ_VERSION' => array('requirements', array('cambiar' => array('/vlgoalbase.php' => array("'1.0.0'", "'1.0'")))),
    'REQ_TAB' => array('requirements', array('cambiar' => array('/vlgoalbase.php' => array("'administration'", "'my_tab'")))),
    'REQ_AUTHOR' => array('requirements', array('cambiar' => array('/vlgoalbase.php' => array("\$this->author = 'Ecom Experts';", '')))),
    'REQ_DISPLAYNAME' => array('requirements', array('cambiar' => array('/vlgoalbase.php' => array("\$this->displayName = \$this->l('Goal base module');", '')))),
    'REQ_DESCRIPTION' => array('requirements', array('cambiar' => array('/vlgoalbase.php' => array("\$this->description = \$this->l('Clean module used by the local validator tests.');", '')))),
    'REQ_COMPLIANCY' => array('requirements', array('cambiar' => array('/vlgoalbase.php' => array("\$this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => _PS_VERSION_];", '')))),
    'REQ_PARENT_CONSTRUCT' => array('requirements', array('cambiar' => array('/vlgoalbase.php' => array('parent::__construct();', '')))),
    'REQ_CONFIG_XML' => array('requirements', array('poner' => array('/config.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n<module>\n<name>vlgoalbase</name>\n<version><![CDATA[2.0.0]]></version>\n<author><![CDATA[Ecom Experts]]></author>\n<tab><![CDATA[administration]]></tab>\n</module>\n"))),
    'STR_ZIP_ONE_FOLDER' => array('structure', array('zip' => 'dos_raices')),
    'STR_ZIP_FOLDER_NAME' => array('structure', array('zip' => 'otra_carpeta')),
    'STR_PS_VERSION_GUARD' => array('structure', array('poner' => array('/classes/VlgoalbaseThing.php' => "<?php\n\n" . $CAB . "class VlgoalbaseThing\n{\n}\n"))),
    'STR_MISPLACED' => array('structure', array('poner' => array('/css/extra.css' => $CAB . ".a {\n  color: red;\n}\n"))),
    'STR_UNNEEDED' => array('structure', array('poner' => array('/.DS_Store' => 'x'))),
    'ERR_PHP_PARSE' => array('errors', array('poner' => array('/classes/Broken.php' => $PHP . "function vlgoalbase_broken( {\n"))),
    'ERR_SMARTY_PARSE' => array('errors', array('cambiar' => array('/views/templates/hook/home.tpl' => array('</div>', "{if \$vlgoalbase_title}\n</div>")))),
    'ERR_INSTALL_RETURN' => array('errors', array('cambiar' => array('/vlgoalbase.php' => array('return parent::uninstall();', 'parent::uninstall();')))),
    'ERR_SQL_IF_EXISTS' => array('errors', array('cambiar' => array('/sql/install.php' => array('CREATE TABLE IF NOT EXISTS', 'CREATE TABLE')))),
    // solo salta con compatibilidad < 1.7 (el oficial ya no lo marca en 1.7.8+: informe 3785345)
    'ERR_IMAGE_TYPE' => array('errors', array('cambiar' => array('/vlgoalbase.php' => array(
        array($HOOK, "        \$type = 'home_default';\n" . $HOOK),
        array("'min' => '1.7.8.0'", "'min' => '1.6.1.0'"),
    )))),
    'ERR_HOOK_DEPRECATED' => array('errors', array('cambiar' => array('/vlgoalbase.php' => array("registerHook('displayHome')", "registerHook('displayProductTab')")))),
    'ERR_HOOK_REMOVED_9' => array('errors', array('cambiar' => array('/vlgoalbase.php' => array("registerHook('displayHome')", "registerHook('actionAjaxDieBefore')")))),
    'ERR_HOOK_DISPLAYSEARCH' => array('errors', array('cambiar' => array('/vlgoalbase.php' => array("registerHook('displayHome')", "registerHook('displaySearch')")))),
    'ERR_ORDERDETAIL' => array('errors', array('cambiar' => array('/views/templates/hook/home.tpl' => array('</div>', "{\$HOOK_DISPLAYORDERDETAIL nofilter}\n</div>")))),
    'ERR_THEME_NAME' => array('errors', array('cambiar' => $codigo("        if ('classic' === \$this->context->shop->theme->getName()) {\n            return '';\n        }"))),
    'ERR_PS9_LIBRARY' => array('errors', array('poner' => array('/classes/VlgoalbaseHttp.php' => $PHP . "use GuzzleHttp\\Client;\n\nclass VlgoalbaseHttp\n{\n    public function get()\n    {\n        return new Client();\n    }\n}\n"))),
    'ERR_PS9_ANNOTATION' => array('errors', array('poner' => array('/classes/VlgoalbaseAnn.php' => $PHP . "use PrestaShopBundle\\Security\\Annotation\\AdminSecurity;\n\nclass VlgoalbaseAnn\n{\n}\n"))),
    'ERR_PS9_CONFIGURATION' => array('errors', array('cambiar' => $codigo("        \$legacy = Configuration::get('PS_LEGACY_IMAGES');"))),
    'ERR_PS9_ADMIN_CONTROLLER' => array('errors', array('poner' => array('/controllers/admin/AdminVlgoalbaseSfController.php' => $PHP . "class AdminVlgoalbaseSfController extends PrestaShopBundle\\Controller\\Admin\\FrameworkBundleAdminController\n{\n}\n"))),
    'ERR_OLD_JQUERY' => array('errors', array('poner' => array('/views/js/jquery-1.11.0.min.js' => "/*! jQuery v1.11.0 | (c) 2005, 2014 jQuery Foundation, Inc. | jquery.org/license */\n!function(a){}(window);\n"))),
    'ERR_PS_VERSION_CONDITION' => array('errors', array('cambiar' => $codigo("        if (_PS_VERSION_ >= '1.7') {\n            \$ok = true;\n        }"))),
    'STR_ENGLISH' => array('structure', array('cambiar' => array('/vlgoalbase.php' => array("\$this->l('Clean module used by the local validator tests.')", "\$this->l('Sustituye fichas sin stock por recomendaciones y recupera productos desactivados.')")))),
    'OPT_HTML_IN_PHP' => array('optimizations', array('cambiar' => $codigo("        \$html = '<div class=\"vlgoalbase\">Hello</div>';"))),
    // medido en el oficial (3785388): una línea COMENTADA con Context:: también cuenta
    'ERR_CONTEXT' => array('errors', array('cambiar' => $codigo('        /* $shop = Context::getContext()->shop; */'))),
    'STR_CONFIG_LANG' => array('structure', array('poner' => array('/config_es.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n<module>\n<name>vlgoalbase</name>\n</module>\n"))),
    'OPT_MIXED_EOL' => array('optimizations', array('poner' => array('/classes/VlgoalbaseMixed.php' => "<?php\n" . $CAB . "if (!defined('_PS_VERSION_')) {\r\n    exit;\r\n}\n\nclass VlgoalbaseMixed\n{\n}\n"))),
    'LIC_MIXED_EOL' => array('licenses', array('poner' => array('/classes/VlgoalbaseMixed.php' => "<?php\n" . $CAB . "if (!defined('_PS_VERSION_')) {\r\n    exit;\r\n}\n\nclass VlgoalbaseMixed\n{\n}\n"))),
    'LIC_FILE_AUTHOR' => array('licenses', array('poner' => array('/classes/VlgoalbaseNoAuthor.php' => "<?php\n/**\n * Goal base helper without author\n */\nif (!defined('_PS_VERSION_')) {\n    exit;\n}\n\nclass VlgoalbaseNoAuthor\n{\n}\n"))),
    'CMP_DEPRECATED' => array('compatibility', array('cambiar' => $codigo("        \$enabled = Module::isEnabled('ps_searchbar');"))),
    'LIC_FILE_DOC_SPACING' => array('licenses', array('cambiar' => array('/sql/install.php' => array("<?php\n/**", "<?php\n\n/**")))),
    'ERR_CONFLICT_MARKS' => array('errors', array('cambiar' => array('/views/js/front.js' => array("document.addEventListener", "<<<<<<< HEAD\ndocument.addEventListener")))),
    'ERR_BOM' => array('errors', array('cambiar' => array('/views/css/front.css' => array('/**', "\xEF\xBB\xBF/**")))),
    'ERR_ENCODING' => array('errors', array('poner' => array('/views/css/latin.css' => $CAB . "/* Caf\xE9 */\n.a {\n  color: red;\n}\n"))),
    'ERR_CONFIG_XML_PARSE' => array('errors', array('poner' => array('/config.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n<module>\n<name>vlgoalbase</name>\n"))),
    'CMP_PHPSTAN' => array('compatibility', array('cambiar' => $codigo("        \$wrong = Configuration::get('VLGOALBASE_TITLE', true);"))),
    'OPT_PS_LIBRARY' => array('optimizations', array('poner' => array('/views/js/jquery.fancybox.js' => $CAB . "var fancy = 1;\n"))),
    'OPT_PHP_EXTENSION' => array('optimizations', array('cambiar' => $codigo("        \$ch = curl_init('https://www.prestashop.com');"))),
    'OPT_EMPTY_FILE' => array('optimizations', array('poner' => array('/views/js/empty.js' => ''))),
    'TRA_QUOTES_PHP' => array('translations', array('cambiar' => array('/vlgoalbase.php' => array("\$this->l('Goal base module')", "\$this->l(\"Goal base module\")")))),
    'TRA_LITERAL_PHP' => array('translations', array('cambiar' => array('/vlgoalbase.php' => array("\$this->l('Goal base module')", '$this->l($this->name)')))),
    'TRA_QUOTES_TPL' => array('translations', array('cambiar' => array('/views/templates/hook/home.tpl' => array("{l s='Welcome' d='Modules.Vlgoalbase.Shop'}", "{l s=\"Welcome\" d='Modules.Vlgoalbase.Shop'}")))),
    'TRA_MOD_TPL' => array('translations', array('cambiar' => array('/views/templates/hook/home.tpl' => array("{l s='Welcome' d='Modules.Vlgoalbase.Shop'}", "{l s='Welcome'}")))),
    'LIC_HEADER' => array('licenses', array('poner' => array('/views/js/nohead.js' => "var vlgoalbaseA = 1;\n"))),
    'LIC_FILE_DOC' => array('licenses', array('poner' => array('/classes/VlgoalbaseNoDoc.php' => "<?php\nif (!defined('_PS_VERSION_')) {\n    exit;\n}\n\nclass VlgoalbaseNoDoc\n{\n}\n"))),
    'LIC_YEAR' => array('licenses', array('cambiar' => array('/views/js/front.js' => array('Copyright since 2007 Ecom Experts', 'Copyright 2007-2020 Ecom Experts')))),
    'LIC_TYPE' => array('licenses', array('cambiar' => array('/views/js/front.js' => array('@license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0', '@license   Commercial license, all rights reserved')))),
    'SEC_HTACCESS' => array('security', array('quitar' => array('/.htaccess'))),
    'SEC_INDEX_PHP' => array('security', array('quitar' => array('/views/js/index.php'))),
    'SEC_FUNCTION' => array('security', array('cambiar' => $codigo("        \$remote = file_get_contents('https://www.prestashop.com');"))),
    'SEC_VARIABLE' => array('security', array('cambiar' => $codigo("        \$raw = \$_GET['vlgoalbase'];"))),
    'SEC_SERIALIZE' => array('security', array('cambiar' => $codigo('        $packed = serialize([1, 2]);'))),
    'SEC_GLOBALS' => array('security', array('cambiar' => $codigo('        global $cookie;'))),
    'SEC_SMARTY_ESCAPE' => array('security', array('cambiar' => array('/views/templates/hook/home.tpl' => array($TPL_VAR, '{$vlgoalbase_title}')))),
    'SEC_SMARTY_STRVAL' => array('security', array('cambiar' => array('/views/templates/hook/home.tpl' => array($TPL_VAR, "{\$vlgoalbase_title|strval|escape:'htmlall':'UTF-8'}")))),
    'SEC_SMARTY_NOFILTER' => array('security', array('cambiar' => array('/views/templates/hook/home.tpl' => array($TPL_VAR, '{$vlgoalbase_title nofilter}')))),
    'STD_PHP_CS_FIXER' => array('standards', array('cambiar' => array('/vlgoalbase.php' => array("\$this->name = 'vlgoalbase';", "\$this->name='vlgoalbase';")))),
);

// Parecidos a un fallo, pero correctos: no debe saltar NINGÚN goal del paso.
$negativos = array(
    'smarty: asignación, if, literal JS con espacio, l, url, intval, escape doble comilla' => array('security', array('cambiar' => array('/views/templates/hook/home.tpl' => array('</div>',
        "{\$counter = 1}{if \$vlgoalbase_title}{/if}\n<script>var o = { a: 1 }; function f(){ return 1; }</script>\n{literal}<style>.x{color:red}</style>{/literal}\n{l s='Hi' d='Modules.Vlgoalbase.Shop'} {\$counter|intval} {\$vlgoalbase_title|escape:\"html\":\"UTF-8\"} {\$list|count}\n{* {\$no_es_salida} *}\n</div>")))),
    'php: header sin Location, método exec(), $this->l con comilla simple, SHOW CREATE TABLE' => array('security,errors,translations', array('cambiar' => $codigo(
        "        header('Content-Type: text/html');\n        Db::getInstance()->execute('SHOW CREATE TABLE `' . _DB_PREFIX_ . 'vlgoalbase`');\n        \$label = \$this->l('Saved');"))),
    'plantilla con <option value="classic"> (un valor, no una comparación con el tema)' => array('errors', array('cambiar' => array('/views/templates/hook/home.tpl' => array('</div>',
        "<select name=\"style\"><option value=\"classic\">{l s='Classic' d='Modules.Vlgoalbase.Shop'}</option></select>\n</div>")))),
    'medido en el oficial (3785345): escribir en $_GET no es usarlo' => array('security', array('cambiar' => $codigo(
        "        \$_GET['module'] = \$this->name;"))),
    'medido en el oficial (3785354): <strong>, <i>, <a>, <table> en el PHP principal y <div> en classes/' => array('optimizations', array(
        'cambiar' => $codigo("        \$lbl = '<strong><i class=\"icon-cogs\"></i> <a href=\"#\">x</a></strong><table><tr><td>y</td></tr></table>';"),
        'poner' => array('/classes/VlgoalbaseView.php' => $PHP . "class VlgoalbaseView\n{\n    public function html()\n    {\n        return '<div id=\"vl\">x</div>';\n    }\n}\n"))),
    'medido en el oficial (3785354): translations/index.php sin bloque de cabecera' => array('licenses', array('poner' => array(
        '/translations/index.php' => "<?php\nheader('Location: ../');\nexit;\n"))),
    'medido en el oficial (3785345): CSS sin cabecera de licencia' => array('licenses', array('poner' => array('/views/css/plain.css' => ".a{color:red}\n"))),
    'medido en el oficial (3785345): comentarios en castellano y home_default con 1.7.8' => array('errors', array('cambiar' => $codigo(
        "        // Solo desde actionDispatcherBefore, antes de que se construya ningún controlador.\n        \$type = 'home_default';"))),
    'comentarios en inglés y código documentado' => array('errors', array('cambiar' => $codigo(
        "        // Assign the title to the template before rendering the block.\n        /* The value comes from the module configuration. */"))),
    'medido en el oficial (3785388): ni @copyright ni @license son obligatorios, solo @author' => array('licenses', array('poner' => array(
        '/classes/VlgoalbaseOnlyAuthor.php' => "<?php\n/**\n * Goal base helper\n *\n * @author    Ecom Experts <ecomyseo@gmail.com>\n */\nif (!defined('_PS_VERSION_')) {\n    exit;\n}\n\nclass VlgoalbaseOnlyAuthor\n{\n}\n"))),
    'todo en CRLF no es mezclar' => array('optimizations,licenses', array('poner' => array(
        '/classes/VlgoalbaseCrlf.php' => str_replace("\n", "\r\n", "<?php\n" . $CAB . "if (!defined('_PS_VERSION_')) {\n    exit;\n}\n\nclass VlgoalbaseCrlf\n{\n}\n")))),
    'serialize en upgrade/ (el oficial lo ignora ahí)' => array('security', array('poner' => array(
        '/upgrade/upgrade-1.1.0.php' => $PHP . "function upgrade_module_1_1_0(\$module)\n{\n    return (bool) serialize([1]);\n}\n",
        '/upgrade/index.php' => "<?php\n\n" . $CAB . "header('Location: ../');\nexit;\n"))),
);

function vlCopiar(string $de, string $a): void
{
    @mkdir($a, 0777, true);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($de, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $dest = $a . substr(str_replace('\\', '/', $f->getPathname()), strlen($de));
        $f->isDir() ? @mkdir($dest, 0777, true) : copy($f->getPathname(), $dest);
    }
}

function vlPreparar(string $base, string $dir, array $cambios): string
{
    vlCopiar($base, $dir);
    foreach ($cambios['quitar'] ?? array() as $r) {
        @unlink($dir . $r);
    }
    foreach ($cambios['poner'] ?? array() as $r => $c) {
        @mkdir(dirname($dir . $r), 0777, true);
        file_put_contents($dir . $r, $c);
    }
    foreach ($cambios['cambiar'] ?? array() as $r => $pares) {
        // un par [buscar, poner] o una lista de pares
        $pares = is_array($pares[0]) ? $pares : array($pares);
        $c = file_get_contents($dir . $r);
        foreach ($pares as $par) {
            if (strpos($c, $par[0]) === false) {
                throw new RuntimeException('El caso no aplica: no se encuentra "' . $par[0] . '" en ' . $r);
            }
            $c = preg_replace('#' . preg_quote($par[0], '#') . '#', str_replace(array('\\', '$'), array('\\\\', '\\$'), $par[1]), $c, 1);
        }
        file_put_contents($dir . $r, $c);
    }
    if (!empty($cambios['zip'])) {
        $zip = dirname($dir) . '/' . basename($dir) . '.zip';
        $z = new ZipArchive();
        $z->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $carpeta = $cambios['zip'] === 'otra_carpeta' ? 'vlgoalbase_v2' : 'vlgoalbase';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $z->addFile($f->getPathname(), $carpeta . substr(str_replace('\\', '/', $f->getPathname()), strlen($dir)));
        }
        if ($cambios['zip'] === 'dos_raices') {
            $z->addFromString('LEEME.txt', 'x');
        }
        $z->close();

        return $zip;
    }

    return $dir;
}

$fallos = 0;
$linea = str_repeat('-', 78);

// 1. BASE LIMPIA ---------------------------------------------------------------
echo $linea . PHP_EOL . "1. BASE LIMPIA (pruebas/base/vlgoalbase, todas las pestañas)\n";
$res = $validador->validar($base, array('ps' => array('8.2.7', '9.2.0')));
$t = VlValidador::totales($res);
$limpia = $t['error'] === 0 && $t['warning'] === 0 && $t['notice'] === 0 && $t['fallos'] === 0;
echo ($limpia ? '   [OK]' : '   [XX]') . sprintf(' errores %d, avisos %d, notas %d, pasos fallidos %d', $t['error'], $t['warning'], $t['notice'], $t['fallos']) . PHP_EOL;
if (!$limpia) {
    ++$fallos;
    echo VlInforme::consola($res, 5);
}

// 2. CAZADAS ---------------------------------------------------------------------
echo $linea . PHP_EOL . "2. CAZADAS: cada goal con un fallo puesto a propósito\n";
$cazadas = 0;
$n = 0;
foreach ($casos as $goal => list($pasos, $cambios)) {
    ++$n;
    $dir = $tmpRaiz . '/c' . $n . '/vlgoalbase';
    try {
        $ruta = vlPreparar($base, $dir, $cambios);
        $op = array('solo' => explode(',', $pasos));
        if ($pasos === 'compatibility') {
            $op['ps'] = array('8.2.7');
        }
        $r = $validador->validar($ruta, $op);
        $hits = 0;
        $otros = array();
        foreach ($r['pasos'] as $p) {
            if ($p->fallo) {
                $otros[] = 'FALLO ' . $p->id . ': ' . $p->fallo;
            }
            foreach ($p->goals as $g => $k) {
                if ($g === $goal) {
                    $hits = $k;
                } elseif ($k > 0) {
                    $otros[] = $g . '=' . $k;
                }
            }
        }
        unset($r);
        if ($hits > 0) {
            ++$cazadas;
            echo sprintf('   [OK] %-26s cazada (%d)%s', $goal, $hits, $otros ? '   [también: ' . implode(', ', $otros) . ']' : '') . PHP_EOL;
        } else {
            ++$fallos;
            echo sprintf('   [XX] %-26s NO CAZADA%s', $goal, $otros ? '   [saltó: ' . implode(', ', $otros) . ']' : '') . PHP_EOL;
        }
    } catch (Throwable $e) {
        ++$fallos;
        echo sprintf('   [XX] %-26s ERROR DEL CASO: %s', $goal, $e->getMessage()) . PHP_EOL;
    }
}
echo "   CAZADAS: $cazadas de $n\n";

// 3. NO FALSOS POSITIVOS ------------------------------------------------------
echo $linea . PHP_EOL . "3. NO FALSOS POSITIVOS\n";
foreach ($negativos as $nombre => list($pasos, $cambios)) {
    ++$n;
    $dir = $tmpRaiz . '/n' . $n . '/vlgoalbase';
    try {
        $ruta = vlPreparar($base, $dir, $cambios);
        $r = $validador->validar($ruta, array('solo' => explode(',', $pasos)));
        $saltan = array();
        foreach ($r['pasos'] as $p) {
            foreach ($p->goals as $g => $k) {
                if ($k > 0) {
                    $saltan[] = $g . '=' . $k . ' (' . implode(' | ', array_slice($p->muestras[$g], 0, 2)) . ')';
                }
            }
        }
        unset($r);
        if ($saltan) {
            ++$fallos;
            echo '   [XX] ' . $nombre . "\n        saltó: " . implode("\n        ", $saltan) . PHP_EOL;
        } else {
            echo '   [OK] ' . $nombre . PHP_EOL;
        }
    } catch (Throwable $e) {
        ++$fallos;
        echo '   [XX] ' . $nombre . ' ERROR DEL CASO: ' . $e->getMessage() . PHP_EOL;
    }
}

// 4. ORO: informe oficial ------------------------------------------------------
if (!$rapido) {
  foreach (glob($raiz . '/oficial/informes/*.json') as $fichOro) {
    $esperado = json_decode(file_get_contents($fichOro), true);
    echo $linea . PHP_EOL . '4. ORO: ' . basename($fichOro, '.json') . " contra el informe real de validator.prestashop.com\n";
    $r = $validador->validar($raiz . '/' . $esperado['zip']);
    foreach ($esperado['pestanas'] as $pid => $e) {
        $p = $r['pasos'][$pid];
        $obtenido = $p->types['error'] + $p->types['warning'];
        $ok = $obtenido === $e['total'];
        if (isset($e['ficheros'])) {
            foreach ($e['ficheros'] as $msg => $rutas) {
                $got = $p->files[$msg] ?? array();
                sort($got);
                sort($rutas);
                if ($got !== $rutas) {
                    $ok = false;
                    echo '        ficheros distintos para "' . substr($msg, 0, 60) . '...": ' . implode(', ', $got) . PHP_EOL;
                }
            }
        }
        if (isset($e['codigo'])) {
            foreach ($e['codigo'] as $item) {
                list($fich, $lin, $msg) = $item;
                $esta = false;
                foreach ($p->code[$fich]['messages'][$msg]['content'] ?? array() as $c) {
                    if ((int) $c['line'] === $lin) {
                        $esta = true;
                    }
                }
                if (!$esta) {
                    $ok = false;
                    echo "        falta $fich:$lin $msg" . PHP_EOL;
                }
            }
        }
        if (!$ok) {
            ++$fallos;
        }
        echo sprintf('   %s %-14s oficial %4d   local %4d', $ok ? '[OK]' : '[XX]', VlGoals::PASOS[$pid], $e['total'], $obtenido) . PHP_EOL;
        // lo que el local da de más (falsos positivos frente al oficial)
        if (!$ok && $obtenido > $e['total']) {
            foreach ($p->muestras as $g => $mu) {
                echo '        ' . $g . ': ' . implode(' | ', array_slice($mu, 0, 4)) . PHP_EOL;
            }
        }
    }
    unset($r);
  }
}

// 4b. ARREGLAR: dónde pone la guarda _PS_VERSION_ -------------------------------------
echo $linea . PHP_EOL . "4b. ARREGLAR: dónde pone --arreglar la guarda _PS_VERSION_\n";
$cab = "<?php\n/**\n * cabecera\n */\n";
$casosGuarda = array(
    'script de cron: DESPUÉS de cargar config.inc.php e init.php' => array(
        $cab . "include dirname(__FILE__) . '/../../config/config.inc.php';\ninclude_once(dirname(__FILE__) . '/../../init.php');\necho 'cron';\n",
        "init.php');", "echo 'cron'"),
    'clase: tras la cabecera, antes de la clase' => array($cab . "class A\n{\n}\n", ' */', 'class A'),
    'con namespace: tras el namespace' => array($cab . "namespace Mi\\Mod;\n\nclass B\n{\n}\n", 'namespace Mi\\Mod;', 'class B'),
);
foreach ($casosGuarda as $nombre => $c) {
    list($src, $antes, $despues) = $c;
    $nuevo = (string) VlArreglar::insertarGuardia($src);
    $pg = strpos($nuevo, "if (!defined('_PS_VERSION_'))");
    $ok = $pg !== false && strpos($nuevo, $antes) < $pg && strpos($nuevo, $despues) > $pg;
    if (!$ok) {
        ++$fallos;
    }
    echo '   ' . ($ok ? '[OK] ' : '[XX] ') . $nombre . PHP_EOL;
}

// 4b2. ARREGLAR deja al 100 % lo que sabe arreglar ------------------------------------
if (!$rapido) {
    echo $linea . PHP_EOL . "4b2. --arreglar sobre una copia con config_es.xml, EOL mezclados y un bloque sin @author\n";
    $dirArr = $tmpRaiz . '/arreglar/vlgoalbase';
    vlCopiar($base, $dirArr);
    file_put_contents($dirArr . '/config_es.xml', "<?xml version=\"1.0\"?>\n<module><name>vlgoalbase</name></module>\n");
    file_put_contents($dirArr . '/vlgoalbase.php', preg_replace('/\n/', "\r\n", (string) file_get_contents($dirArr . '/vlgoalbase.php'), 3));
    file_put_contents($dirArr . '/sql/install.php', preg_replace('#\s*\* @author[^\n]*#', '', (string) file_get_contents($dirArr . '/sql/install.php'), 1));
    $antes = VlValidador::totales($validador->validar($dirArr));
    (new VlArreglar($raiz))->arreglar($dirArr);
    $res = $validador->validar($dirArr);
    $desp = VlValidador::totales($res);
    $quedan = array();
    foreach ($res['pasos'] as $p) {
        foreach ($p->goals as $g => $nn) {
            if ($nn) {
                $quedan[] = $g . ' ' . $nn;
            }
        }
    }
    $ok = $antes['error'] + $antes['warning'] > 0 && $desp['error'] + $desp['warning'] + $desp['fallos'] === 0;
    if (!$ok) {
        ++$fallos;
    }
    echo '   ' . ($ok ? '[OK] ' : '[XX] ') . 'antes ' . ($antes['error'] + $antes['warning']) . ' resultados, después ' . ($desp['error'] + $desp['warning'])
        . ($quedan ? ' (quedan: ' . implode(', ', $quedan) . ')' : '') . PHP_EOL;
}

// 4c. Medidas sueltas del informe 3785388 ---------------------------------------------
echo $linea . PHP_EOL . "4c. MEDIDAS SUELTAS (informe 3785388)\n";
$sueltas = array(
    'todo LF no mezcla' => !VlComprobaciones::finesMezclados("a\nb\n"),
    'todo CRLF no mezcla' => !VlComprobaciones::finesMezclados("a\r\nb\r\n"),
    'CRLF y luego LF mezcla' => VlComprobaciones::finesMezclados("a\r\nb\nc"),
    'LF y luego CRLF mezcla' => VlComprobaciones::finesMezclados("a\nb\r\nc"),
    '<script en una cadena del PHP principal es HTML' => VlComprobaciones::lineasConHtml("<?php\n\$js = '<script>var a = 1;</script>';\n") === array(2),
    '<strong>, <a>, <table> siguen sin contar' => VlComprobaciones::lineasConHtml("<?php\n\$x = '<strong><a href=\"#\">y</a></strong><table></table>';\n") === array(),
    'Context:: en un comentario cuenta; MyContext:: no' => VlComprobaciones::lineasCon("<?php\n/* \$c = Context::getContext(); */\n\$d = MyContext::get();\n\$e = \\Context::getContext();\n", '#(?<![A-Za-z0-9_])Context::#') === array(2, 4),
    'línea del «*/» del bloque de cabecera' => VlComprobaciones::bloqueDeFichero("<?php\n/**\n * a\n * b\n */\nclass X {}\n")[1] === 5,
);
foreach ($sueltas as $nombre => $ok) {
    if (!$ok) {
        ++$fallos;
    }
    echo '   ' . ($ok ? '[OK] ' : '[XX] ') . $nombre . PHP_EOL;
}

// 5. COBERTURA -------------------------------------------------------------------
echo $linea . PHP_EOL . "5. COBERTURA: todo goal tiene su caso\n";
$sinCaso = array_diff(array_keys(VlGoals::todos()), array_keys($casos));
if ($sinCaso) {
    ++$fallos;
    echo '   [XX] sin caso: ' . implode(', ', $sinCaso) . PHP_EOL;
} else {
    echo '   [OK] ' . count($casos) . ' goals, todos con caso' . PHP_EOL;
}

VlModulo::borrarArbol($tmpRaiz);
echo $linea . PHP_EOL . ($fallos === 0 ? "TODO EN VERDE\n" : "FALLOS: $fallos\n");
exit($fallos === 0 ? 0 : 1);
