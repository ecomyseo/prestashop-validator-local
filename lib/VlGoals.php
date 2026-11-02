<?php
/**
 * Registro de GOALS: cada verificación del validator es un goal con identificador propio.
 *
 * Un goal se cumple cuando el módulo no tiene ninguna ocurrencia de él. Cada goal tiene en
 * pruebas/goals.php un caso que lo incumple (debe saltar) y el módulo base que lo cumple
 * (no debe saltar): así se demuestra que la verificación caza lo que tiene que cazar.
 *
 * origen:
 *   verificado   -> mensaje y comportamiento medidos contra un informe oficial (5.14.6)
 *   herramienta  -> la misma herramienta y la misma configuración que el oficial (php-cs-fixer, PHPStan)
 *   reglas       -> dato literal de https://validator.prestashop.com/rules (reglas/rules.json)
 *   documentado  -> regla del changelog oficial o del checklist de validación; texto reconstruido
 */
class VlGoals
{
    const PASOS = array(
        'requirements' => 'Requirements',
        'structure' => 'Structure',
        'errors' => 'Errors',
        'compatibility' => 'Compatibility',
        'optimizations' => 'Optimizations',
        'translations' => 'Translations',
        'licenses' => 'Licenses',
        'security' => 'Security',
        'standards' => 'Standards',
    );

    /** Texto de cabecera de cada pestaña (literal del informe oficial). */
    const INTRO = array(
        'requirements' => 'The essential bootstrap lines are not respected, the extension may not work properly.',
        'structure' => 'Some files are misplaced or should be removed:',
        'errors' => "PrestaShop's functions and methods of the core are not properly used.",
        'compatibility' => 'Some of the classes, methods & functions used in the module does not exist.',
        'optimizations' => 'The module can be cleaned and optimized. For performances reasons, please take care of the recommandations below.',
        'translations' => 'All of the texts are not translated correctly.',
        'licenses' => 'Every single file of the package has to contain a valid license header.',
        'security' => 'The addon is not secure enough, please check the recommandations below.',
        'standards' => 'Your code does not follow the coding standards of the PrestaShop Open-Source project (PHP CS Fixer).',
    );

    const INTRO_LINK = array(
        'requirements' => 'https://devdocs.prestashop-project.org/8/modules/creation/tutorial/',
        'structure' => 'https://devdocs.prestashop-project.org/8/modules/creation/module-file-structure/',
        'standards' => 'https://devdocs.prestashop-project.org/1.7/development/coding-standards/',
    );

    const DOC = 'https://docs.cloud.prestashop.com/10-validation-checklist/';

    /** @var array<string, array> */
    private static $goals;

    public static function todos(): array
    {
        if (self::$goals !== null) {
            return self::$goals;
        }
        $g = array();
        $d = function ($id, $paso, $tipo, $titulo, $mensaje, $origen, $link = null) use (&$g) {
            $g[$id] = array(
                'id' => $id,
                'paso' => $paso,
                'tipo' => $tipo,
                'titulo' => $titulo,
                'mensaje' => $mensaje,
                'origen' => $origen,
                'link' => $link,
            );
        };

        // ---------------------------------------------------------------- Requirements
        $d('REQ_MAIN_FILE', 'requirements', 'error', 'Existe el fichero principal <modulo>/<modulo>.php',
            'The main file of the module (%s) is missing.', 'documentado');
        $d('REQ_MAIN_CLASS', 'requirements', 'error', 'El fichero principal declara la clase del módulo',
            'The main file must declare a class named like the module (%s).', 'documentado');
        $d('REQ_EXTENDS', 'requirements', 'error', 'La clase principal extiende Module, PaymentModule o CarrierModule',
            'The main class must extend one of these classes: %s.', 'reglas');
        $d('REQ_NAME', 'requirements', 'error', '$this->name existe, coincide con la carpeta y cumple /^[a-z0-9_]{4,}$/',
            'The module name (%s) is not valid: it must match the folder name and the pattern %s.', 'reglas');
        $d('REQ_VERSION', 'requirements', 'error', '$this->version existe y es X.Y.Z',
            'The module version (%s) is not valid, expected format: 1.0.0.', 'reglas');
        $d('REQ_TAB', 'requirements', 'error', '$this->tab existe y es una categoría válida',
            'The module tab (%s) is not valid. Allowed values: %s.', 'reglas');
        $d('REQ_AUTHOR', 'requirements', 'error', '$this->author está definido',
            'The module author is not defined in the constructor ($this->author).', 'documentado');
        $d('REQ_DISPLAYNAME', 'requirements', 'error', '$this->displayName está definido',
            'The module display name is not defined in the constructor ($this->displayName).', 'documentado');
        $d('REQ_DESCRIPTION', 'requirements', 'error', '$this->description está definido',
            'The module description is not defined in the constructor ($this->description).', 'documentado');
        $d('REQ_COMPLIANCY', 'requirements', 'error', '$this->ps_versions_compliancy declarado con min y max',
            "The PrestaShop version compliancy is not declared: add \$this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_); in the constructor.",
            'documentado', self::DOC . '#prestashop-version-compliancy-is-declared');
        $d('REQ_PARENT_CONSTRUCT', 'requirements', 'error', 'El constructor llama a parent::__construct()',
            'The constructor of the main class must call parent::__construct().', 'documentado');
        $d('REQ_CONFIG_XML', 'requirements', 'warning', 'config.xml coincide con la clase principal',
            'The value of <%s> in config.xml (%s) is different from the main class (%s).', 'documentado');

        // ---------------------------------------------------------------- Structure
        $d('STR_ZIP_ONE_FOLDER', 'structure', 'error', 'El ZIP contiene exactamente una carpeta',
            'The archive must contain exactly one folder, named like the module.', 'documentado');
        $d('STR_ZIP_FOLDER_NAME', 'structure', 'error', 'La carpeta del ZIP se llama como el módulo',
            'The folder of the archive (%s) must be named like the module (%s).', 'documentado');
        $d('STR_PS_VERSION_GUARD', 'structure', 'error', 'Todos los PHP llevan if (!defined(\'_PS_VERSION_\')) { exit; }',
            "To ensure that PHP files are executed in the PrestaShop context, you must add the following code to the beginning of all PHP files: <code>if (!defined('_PS_VERSION_')) { exit; }</code>",
            'verificado', self::DOC . '#php-files-are-executed-in-prestashop-context');
        $d('STR_MISPLACED', 'structure', 'error', 'CSS, JS, imágenes, fuentes, SVG y TPL en su carpeta de views/',
            '%s must be stored in %s', 'reglas', 'https://devdocs.prestashop-project.org/8/modules/creation/module-file-structure/');
        $d('STR_ENGLISH', 'structure', 'error', 'La descripción del módulo está en inglés',
            'It seems your module has not been developed in English. Please make sure you use only English to develop it. You can use Google Translate to make sure your description is auto detected as English. The validator may have detected proper names and considered them as not translated. In this case, please use a description with more English words.',
            'verificado');
        $d('STR_UNNEEDED', 'structure', 'error', 'Sin ficheros innecesarios en producción (.DS_Store, .git, Thumbs.db...)',
            'These files and folders are not needed on production and must be removed:', 'reglas');
        // Medido (informe 3785388): config_es.xml en la raíz -> «File /config_es.xml should be removed» (error).
        $d('STR_CONFIG_LANG', 'structure', 'error', 'Sin config_xx.xml en la raíz (los genera PrestaShop)',
            'File %s should be removed', 'verificado');

        // ---------------------------------------------------------------- Errors
        $d('ERR_PHP_PARSE', 'errors', 'error', 'Todos los PHP se pueden analizar (sin errores de sintaxis)',
            'Parse error: %s', 'documentado');
        $d('ERR_SMARTY_PARSE', 'errors', 'error', 'Todas las plantillas .tpl compilan con Smarty',
            'Smarty error: %s', 'documentado');
        $d('ERR_INSTALL_RETURN', 'errors', 'error', 'install() y uninstall() devuelven un valor',
            'The method %s() must return a value: a forgotten value means for PrestaShop something went wrong.', 'documentado');
        $d('ERR_SQL_IF_EXISTS', 'errors', 'error', 'CREATE TABLE con IF NOT EXISTS y DROP TABLE con IF EXISTS',
            'On table creation or deletion, you must add "IF (NOT) EXISTS" to avoid database exceptions.', 'documentado');
        $d('ERR_IMAGE_TYPE', 'errors', 'error', 'Sin tipos de imagen escritos a mano (home_default...)',
            "Hard coded images types are forbidden. Use ImageType::getFormattedName('%s') instead.", 'documentado');
        $d('ERR_HOOK_DEPRECATED', 'errors', 'warning', 'Sin hooks obsoletos en las versiones declaradas',
            'The hook %s is deprecated since PrestaShop %s.', 'documentado');
        $d('ERR_HOOK_REMOVED_9', 'errors', 'error', 'Sin hooks eliminados en PrestaShop 9',
            'The hook %s has been deleted in PrestaShop 9.0.0.', 'documentado');
        $d('ERR_HOOK_DISPLAYSEARCH', 'errors', 'error', 'Sin hook displaySearch (PrestaShop 9.1+)',
            'The hook displaySearch is deprecated since PrestaShop 9.1 and is not displayed anymore.', 'documentado');
        $d('ERR_ORDERDETAIL', 'errors', 'error', 'displayOrderDetail en vez de $HOOK_DISPLAYORDERDETAIL (9.1+)',
            'The variable $HOOK_DISPLAYORDERDETAIL does not exist anymore since PrestaShop 9.1: register the hook displayOrderDetail instead.', 'documentado');
        $d('ERR_THEME_NAME', 'errors', 'error', 'Sin nombre de tema escrito a mano (9.1+)',
            'The theme name (%s) must not be hard coded: since PrestaShop 9.1 the default theme can be any theme (use Context::getContext()->shop->theme->getName()).', 'documentado');
        $d('ERR_PS9_LIBRARY', 'errors', 'error', 'Sin librerías eliminadas en PrestaShop 9 (Guzzle, SwiftMailer, Tactician, FrameworkExtraBundle)',
            '%s is not available anymore in PrestaShop 9.', 'documentado');
        $d('ERR_PS9_ANNOTATION', 'errors', 'warning', 'Sin anotaciones de PrestaShopBundle (PrestaShop 9)',
            'PrestaShopBundle\\Security\\Annotation is deprecated in PrestaShop 9: use the PHP attributes instead.', 'documentado');
        $d('ERR_PS9_CONFIGURATION', 'errors', 'error', 'Sin PS_LEGACY_IMAGES ni PS_HIGHT_DPI (PrestaShop 9)',
            'The configuration %s has been deleted in PrestaShop 9.', 'documentado');
        $d('ERR_PS9_ADMIN_CONTROLLER', 'errors', 'warning', 'Sin FrameworkBundleAdminController (obsoleto en 9)',
            'FrameworkBundleAdminController is deprecated since PrestaShop 9.0, extend PrestaShopAdminController instead.', 'documentado');
        $d('ERR_OLD_JQUERY', 'errors', 'error', 'Sin jQuery antiguo incluido en el módulo',
            'An old version of jQuery (%s) is embedded in the module: use the version provided by PrestaShop.', 'documentado');
        $d('ERR_PS_VERSION_CONDITION', 'errors', 'error', '_PS_VERSION_ se compara con version_compare()',
            '_PS_VERSION_ must be compared with version_compare().', 'documentado');
        // Medido (informe 3785354): cada línea con Context:: es un AVISO, en cualquier fichero PHP.
        // Medido (informe 3785388): también si la línea está dentro de un comentario («/*  $x = Context::...»).
        $d('ERR_CONTEXT', 'errors', 'warning', 'Sin la clase Context (Context::getContext()) en PrestaShop 8/9',
            'Use of class Context', 'verificado');
        $d('ERR_CONFLICT_MARKS', 'errors', 'error', 'Sin marcas de conflicto de Git (<<< === >>>)',
            'Conflict marks have been found in this file.', 'reglas');
        $d('ERR_BOM', 'errors', 'error', 'Sin marca BOM al principio de los ficheros',
            'A BOM marker has been found at the beginning of the file.', 'documentado');
        $d('ERR_ENCODING', 'errors', 'error', 'Ficheros de texto en UTF-8',
            'The file is not encoded in UTF-8.', 'documentado');
        $d('ERR_CONFIG_XML_PARSE', 'errors', 'error', 'config.xml es un XML válido',
            'The config.xml file cannot be parsed: %s', 'documentado');

        // ---------------------------------------------------------------- Compatibility
        $d('CMP_PHPSTAN', 'compatibility', 'error', 'PHPStan nivel 5 sin errores contra cada PrestaShop declarado',
            '%s', 'herramienta');
        // Medido (informe 3785354): NOTA (no cuenta) por llamar a un método marcado @deprecated en el núcleo.
        $d('CMP_DEPRECATED', 'compatibility', 'notice', 'Sin métodos o clases obsoletos del núcleo',
            "Method '%s' is deprecated from PrestaShop %s", 'verificado');

        // ---------------------------------------------------------------- Optimizations
        $d('OPT_PS_LIBRARY', 'optimizations', 'warning', 'Sin librerías JS que ya trae PrestaShop',
            'This library (%s) is already included in PrestaShop, use it instead of embedding it.', 'reglas');
        $d('OPT_PHP_EXTENSION', 'optimizations', 'warning', 'cURL y SOAP solo tras comprobar que la extensión existe',
            'The PHP extension %s may not be installed: check it before use (%s).', 'reglas');
        $d('OPT_EMPTY_FILE', 'optimizations', 'warning', 'Sin ficheros vacíos',
            'Empty files must be removed:', 'documentado', self::DOC . '#empty-and-generated-files-have-been-removed');
        // Medido (informe 3785354): ERROR por cada línea de una cadena del fichero principal con
        // <div, <p, <span o <h1..<h6 (no cuentan <strong>, <i>, <a>, <br>, <table>, <tr>, <td>, <button>...).
        // Medido (informe 3785388): <script también cuenta.
        $d('OPT_HTML_IN_PHP', 'optimizations', 'error', 'Sin HTML (div, p, span, h1-h6, script) en el PHP principal: va en plantillas',
            'The use of smarty templates is mandatory to display HTML. Your PHP code should not contain HTML.', 'verificado');
        // Medido (informe 3785388): AVISO de PHP_CodeSniffer en la línea 1 de cada PHP con finales de línea
        // mezclados (CRLF y LF); sale igual en Optimizations y en Licenses.
        $d('OPT_MIXED_EOL', 'optimizations', 'warning', 'Cada PHP con un solo tipo de final de línea (sin mezclar CRLF y LF)',
            'File has mixed line endings; this may cause incorrect results', 'verificado');

        // ---------------------------------------------------------------- Translations
        $d('TRA_QUOTES_PHP', 'translations', 'error', 'Cadenas de $this->l() entre comillas simples',
            'Translations must be enclosed in single quotes.', 'documentado');
        $d('TRA_LITERAL_PHP', 'translations', 'error', 'Cadenas de $this->l() literales (sin variables)',
            'Translated strings must be literal strings, variables cannot be translated.', 'documentado');
        $d('TRA_QUOTES_TPL', 'translations', 'error', "{l s='...'} entre comillas simples",
            'Translations must be enclosed in single quotes.', 'documentado');
        $d('TRA_MOD_TPL', 'translations', 'error', "{l s='...'} lleva mod='<modulo>' o d='Modules...'",
            "The translation must have the parameter mod='%s' (or a domain d='Modules.%s...').", 'documentado');

        // ---------------------------------------------------------------- Licenses
        // Medido (informe 3785345): PHP -> "Missing file doc comment" (línea 1, error) en TODOS los PHP,
        // index.php incluidos; JS y TPL -> "Missing license header" (lista de ficheros, error); CSS no se mira.
        $d('LIC_FILE_DOC', 'licenses', 'error', 'Cada PHP empieza por un bloque /** ... */ tras <?php',
            'Missing file doc comment', 'verificado');
        // Medido (informe 3785354): una línea en blanco entre <?php y el bloque de cabecera es ERROR.
        $d('LIC_FILE_DOC_SPACING', 'licenses', 'error', 'Sin líneas en blanco entre <?php y el bloque de cabecera',
            'There must be no blank lines before the file comment', 'verificado');
        // Medido (informe 3785388): bloque de cabecera sin @author -> error en la línea del «*/» que lo
        // cierra. Ni @copyright ni @license se exigen (ficheros sin ninguno de los tres solo dieron este).
        $d('LIC_FILE_AUTHOR', 'licenses', 'error', 'El bloque de cabecera de cada PHP lleva @author',
            'Missing @author tag in file comment', 'verificado');
        $d('LIC_MIXED_EOL', 'licenses', 'warning', 'Cada PHP con un solo tipo de final de línea (sin mezclar CRLF y LF)',
            'File has mixed line endings; this may cause incorrect results', 'verificado');
        $d('LIC_HEADER', 'licenses', 'error', 'Cabecera de licencia en cada JS y TPL',
            'Missing license header', 'verificado');
        $d('LIC_YEAR', 'licenses', 'warning', 'Año del copyright al día',
            'The copyright year is outdated (%s).', 'reglas');
        $d('LIC_TYPE', 'licenses', 'notice', 'Licencia compatible (AFL, OSL, MIT, BSD, Apache, ISC, EUPL)',
            'The license (%s) is not compatible with the PrestaShop Marketplace (allowed: AFL, OSL, MIT, BSD, Apache, ISC, EUPL).', 'documentado',
            self::DOC . '#the-license-is-supported');

        // ---------------------------------------------------------------- Security
        $d('SEC_HTACCESS', 'security', 'error', 'Existe .htaccess en la raíz',
            'To prevent someone from listing the files of the module, an .htaccess file must be present in the root folder.',
            'verificado', self::DOC . '#an-htaccess-file-exists-in-the-root-folder-of-the-module');
        $d('SEC_INDEX_PHP', 'security', 'error', 'index.php en cada carpeta (salvo vendor/)',
            'To prevent someone from reaching the content of a repository, an index.php file must be present in each folder.',
            'documentado', self::DOC . '#an-index-php-file-exists-in-each-folder');
        $d('SEC_FUNCTION', 'security', 'error', 'Sin funciones prohibidas (exec, eval, file_get_contents, header Location...)',
            '%s', 'reglas');
        $d('SEC_VARIABLE', 'security', 'error', 'Sin $_GET, $_POST, $_COOKIE ni $_SESSION',
            '%s', 'reglas');
        $d('SEC_SERIALIZE', 'security', 'error', 'Sin serialize() ni unserialize()',
            'serialize() and unserialize() are forbidden, use json_encode() and json_decode() instead.',
            'documentado', self::DOC . '#no-unsafe-methods-are-used');
        $d('SEC_GLOBALS', 'security', 'error', 'Sin variables globales (global / $GLOBALS)',
            'The use of globals is forbidden.', 'documentado');
        $d('SEC_SMARTY_ESCAPE', 'security', 'error', 'Variables Smarty escapadas',
            '%s', 'reglas', self::DOC . '#smarty-variables-are-escaped');
        $d('SEC_SMARTY_STRVAL', 'security', 'error', 'Sin el modificador |strval en Smarty',
            'The Smarty modifier strval is not allowed.', 'documentado');
        $d('SEC_SMARTY_NOFILTER', 'security', 'notice', 'nofilter solo cuando no hay otra opción',
            '{$var nofilter} is strongly discouraged and will be analysed on a case-by-case basis (XSS risk).',
            'documentado', self::DOC . '#smarty-variables-are-escaped');

        // ---------------------------------------------------------------- Standards
        $d('STD_PHP_CS_FIXER', 'standards', 'error', 'PHP CS Fixer (reglas de PrestaShop) sin cambios pendientes',
            '%s', 'herramienta');

        self::$goals = $g;

        return $g;
    }

    public static function get(string $id): array
    {
        $g = self::todos();
        if (!isset($g[$id])) {
            throw new RuntimeException('Goal desconocido: ' . $id);
        }

        return $g[$id];
    }

    public static function delPaso(string $paso): array
    {
        return array_filter(self::todos(), function ($g) use ($paso) {
            return $g['paso'] === $paso;
        });
    }
}
