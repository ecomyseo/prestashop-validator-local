<?php
/**
 * Bootstrap de PHPStan para la pestaña «Compatibility».
 *
 * Réplica de prestashop/php-dev-tools/phpstan/bootstrap.php (MIT), que es lo que usa el
 * validator oficial: define las constantes del back-office y carga el autoload de la
 * versión de PrestaShop indicada en _PS_ROOT_DIR_.
 *
 * Diferencia: config/bootstrap.php monta el contenedor de Symfony y en una copia sin
 * instalar (sin app/config/parameters.php) puede lanzar excepciones; aquí se tolera,
 * porque a PHPStan solo le hace falta que las clases se puedan autocargar.
 */
function vlRequireIfExists(string $ruta): bool
{
    if (file_exists($ruta)) {
        require_once $ruta;

        return true;
    }

    return false;
}

$rootDir = getenv('_PS_ROOT_DIR_');
if (!$rootDir || !realpath($rootDir)) {
    fwrite(STDERR, '[ERROR] _PS_ROOT_DIR_ no apunta a una carpeta de PrestaShop' . PHP_EOL);
    exit(1);
}
$rootDir = rtrim(str_replace('\\', '/', realpath($rootDir)), '/');

// Autoload del propio módulo (si trae vendor/ de Composer)
$moduloDir = getenv('VL_MODULE_DIR');
if ($moduloDir && file_exists($moduloDir . '/vendor/autoload.php')) {
    require_once $moduloDir . '/vendor/autoload.php';
}

if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', $rootDir . '/admin-dev/');
}
if (!defined('PS_ADMIN_DIR')) {
    define('PS_ADMIN_DIR', _PS_ADMIN_DIR_);
}
if (!defined('__PS_BASE_URI__')) {
    define('__PS_BASE_URI__', '/');
}
if (!defined('_THEME_NAME_')) {
    define('_THEME_NAME_', 'default-bootstrap');
}

$nivel = error_reporting();
error_reporting($nivel & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_NOTICE & ~E_WARNING);
try {
    vlRequireIfExists($rootDir . '/tools/smarty/Smarty.class.php');
    vlRequireIfExists($rootDir . '/config/defines.inc.php');
    vlRequireIfExists($rootDir . '/config/autoload.php');
    vlRequireIfExists($rootDir . '/config/defines_uri.inc.php');
} catch (Throwable $e) {
    fwrite(STDERR, '[AVISO] bootstrap de PrestaShop: ' . $e->getMessage() . PHP_EOL);
}
// Constantes que en una tienda instalada define config/bootstrap.php a partir de
// app/config/parameters.php. El oficial corre sobre una tienda instalada: sin ellas,
// PHPStan daría «Constant _DB_PREFIX_ not found», que el oficial no da (medido).
foreach (array(
    '_DB_SERVER_' => 'localhost', '_DB_NAME_' => 'prestashop', '_DB_USER_' => 'root', '_DB_PASSWD_' => '',
    '_DB_PREFIX_' => 'ps_', '_MYSQL_ENGINE_' => 'InnoDB', '_PS_CACHING_SYSTEM_' => 'CacheMemcache',
    '_PS_CACHE_ENABLED_' => '0', '_COOKIE_KEY_' => 'validatorlocal', '_COOKIE_IV_' => 'validatorlocal',
    '_PS_CREATION_DATE_' => '2026-01-01', '_RIJNDAEL_KEY_' => 'validatorlocal', '_RIJNDAEL_IV_' => 'validatorlocal',
    '_NEW_COOKIE_KEY_' => 'validatorlocal',
) as $constante => $valor) {
    if (!defined($constante)) {
        define($constante, $valor);
    }
}
if (getenv('VL_PS_BOOTSTRAP') === '1') {
    try {
        vlRequireIfExists($rootDir . '/config/bootstrap.php');
    } catch (Throwable $e) {
        fwrite(STDERR, '[AVISO] config/bootstrap.php: ' . $e->getMessage() . PHP_EOL);
    }
}
error_reporting($nivel);
