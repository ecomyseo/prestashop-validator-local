<?php
/**
 * Comprobaciones propias (todas salvo Compatibility y Standards, que usan herramientas externas).
 * Un método por pestaña del validator oficial.
 */

use PhpParser\Node;
use PhpParser\NodeFinder;

class VlComprobaciones
{
    /** @var VlModulo */
    private $m;

    public function __construct(VlModulo $m)
    {
        $this->m = $m;
    }

    private function g(string $id): array
    {
        return VlGoals::get($id);
    }

    private function msg(string $id, ...$args): string
    {
        $g = $this->g($id);

        return $args ? vsprintf($g['mensaje'], $args) : $g['mensaje'];
    }

    private function code(VlPaso $p, string $goal, string $rel, int $linea, ?string $mensaje = null, ?string $tipo = null): void
    {
        $p->addCode($goal, $rel, $mensaje ?? $this->msg($goal), max(0, $linea), $this->m->linea($rel, $linea), $this->m->bloque($rel, $linea), $tipo);
    }

    /** PHP que se revisan (sin vendor ni carpetas ignoradas). */
    private function phps(): array
    {
        return array_values(array_filter($this->m->phps(), function ($f) {
            return !$this->m->esIgnorado(dirname($f) === '/' ? '/' : dirname($f));
        }));
    }

    private function tpls(): array
    {
        return array_values(array_filter($this->m->conExtension(array('tpl')), function ($f) {
            return !$this->m->esIgnorado(dirname($f));
        }));
    }

    /** @return Node[] */
    private function nodos(string $rel, string $clase): array
    {
        return $this->m->buscar($rel, $clase);
    }

    private static function nombreFuncion(Node\Expr\FuncCall $f): ?string
    {
        return $f->name instanceof Node\Name ? strtolower(ltrim($f->name->toString(), '\\')) : null;
    }

    /** Todas las cadenas literales de un fichero PHP (String_ y trozos de "..."), con su línea. */
    private function cadenas(string $rel): array
    {
        $out = array();
        foreach ($this->nodos($rel, Node\Scalar\String_::class) as $s) {
            $out[] = array($s->value, $s->getStartLine(), $s);
        }
        foreach ($this->nodos($rel, Node\Scalar\EncapsedStringPart::class) as $s) {
            $out[] = array($s->value, $s->getStartLine(), $s);
        }

        return $out;
    }

    // ===================================================================== Requirements

    public function requirements(VlPaso $p): void
    {
        $m = $this->m;
        $principal = $m->principal();
        if (!in_array($principal, $m->ficheros, true)) {
            $p->addFile('REQ_MAIN_FILE', $this->msg('REQ_MAIN_FILE', $principal), $principal);

            return;
        }
        if (!$m->clase || strtolower((string) $m->claseNombre) !== strtolower($m->carpeta)) {
            $this->code($p, 'REQ_MAIN_CLASS', $principal, $m->clase ? $m->clase->getStartLine() : 1, $this->msg('REQ_MAIN_CLASS', $m->carpeta));

            return;
        }
        $linClase = $m->clase->getStartLine();
        $permitidas = $m->reglas['Possible class extends'];
        $ext = $m->claseExtiende ? basename(str_replace('\\', '/', $m->claseExtiende)) : '';
        if (!in_array(strtolower($ext), array_map('strtolower', $permitidas), true)) {
            $this->code($p, 'REQ_EXTENDS', $principal, $linClase, $this->msg('REQ_EXTENDS', implode(', ', $permitidas)));
        }
        $id = $m->id;
        $lin = function ($k) use ($id, $linClase) {
            return $id['__linea_' . $k] ?? $linClase;
        };
        $regexNombre = $m->reglas['Module identity']['Name regex'];
        $nombre = $id['name'] ?? null;
        if (!is_string($nombre) || $nombre !== $m->carpeta || !preg_match($regexNombre, $nombre)) {
            $this->code($p, 'REQ_NAME', $principal, $lin('name'), $this->msg('REQ_NAME', is_string($nombre) ? $nombre : 'undefined', $regexNombre));
        }
        $version = $id['version'] ?? null;
        $regexVersion = $m->reglas['Module identity']['Version regex'];
        if (!is_string($version) || !preg_match($regexVersion . 'u', $version)) {
            $this->code($p, 'REQ_VERSION', $principal, $lin('version'), $this->msg('REQ_VERSION', is_string($version) ? $version : 'undefined'));
        }
        $tab = $id['tab'] ?? null;
        $tabs = $m->reglas['Module identity']['Tab values'];
        if (!is_string($tab) || !in_array($tab, $tabs, true)) {
            $this->code($p, 'REQ_TAB', $principal, $lin('tab'), $this->msg('REQ_TAB', is_string($tab) ? $tab : 'undefined', implode(', ', $tabs)));
        }
        foreach (array('author' => 'REQ_AUTHOR', 'displayName' => 'REQ_DISPLAYNAME', 'description' => 'REQ_DESCRIPTION') as $k => $goal) {
            if (!array_key_exists($k, $id) || $id[$k] === '' || $id[$k] === null) {
                $this->code($p, $goal, $principal, $linClase);
            }
        }
        $c = $id['ps_versions_compliancy'] ?? null;
        if (!is_array($c) || !isset($c['min'])) {
            $this->code($p, 'REQ_COMPLIANCY', $principal, $lin('ps_versions_compliancy'));
        }
        if (!$m->llamaParent) {
            $ctor = $m->clase->getMethod('__construct');
            $this->code($p, 'REQ_PARENT_CONSTRUCT', $principal, $ctor ? $ctor->getStartLine() : $linClase);
        }
        // config.xml frente a la clase principal
        if (in_array('/config.xml', $m->ficheros, true)) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($m->leer('/config.xml'));
            libxml_clear_errors();
            if ($xml) {
                $mapa = array('name' => 'name', 'version' => 'version', 'author' => 'author', 'tab' => 'tab');
                foreach ($mapa as $tagXml => $prop) {
                    if (!isset($xml->{$tagXml}) || !isset($id[$prop]) || !is_scalar($id[$prop])) {
                        continue;
                    }
                    $vx = trim((string) $xml->{$tagXml});
                    if ($vx !== '' && $vx !== (string) $id[$prop]) {
                        $p->addCode('REQ_CONFIG_XML', '/config.xml', $this->msg('REQ_CONFIG_XML', $tagXml, $vx, (string) $id[$prop]), 1, '<' . $tagXml . '>' . $vx . '</' . $tagXml . '>');
                    }
                }
            }
        }
    }

    // ===================================================================== Structure

    public function structure(VlPaso $p): void
    {
        $m = $this->m;
        if ($m->zip) {
            $raiz = array_values(array_filter($m->zipRaiz, function ($e) {
                return $e !== '__MACOSX' && $e !== '.DS_Store';
            }));
            if (count($raiz) !== 1 || !is_dir($m->tmp . '/' . $raiz[0])) {
                $p->addFile('STR_ZIP_ONE_FOLDER', $this->msg('STR_ZIP_ONE_FOLDER'), '/' . implode(', /', $raiz));
            } elseif (isset($m->id['name']) && is_string($m->id['name']) && $raiz[0] !== $m->id['name']) {
                $p->addFile('STR_ZIP_FOLDER_NAME', $this->msg('STR_ZIP_FOLDER_NAME', $raiz[0], $m->id['name']), '/' . $raiz[0]);
            }
        }
        // Descripción en inglés (el oficial la pasa por un detector de idioma)
        $desc = $m->id['description'] ?? null;
        if (is_string($desc) && $desc !== '' && !VlIdioma::esIngles($desc)) {
            $f = $m->ficheroClase ?: $m->principal();
            $l = (int) ($m->id['__linea_description'] ?? 1);
            $this->code($p, 'STR_ENGLISH', $f, $l);
        }
        // logo.png: el oficial NO lo comprueba (medido: el módulo del generador no lo trae y
        // su pestaña Structure solo marca la guarda _PS_VERSION_). No se añade goal.
        // if (!defined('_PS_VERSION_')) { exit; }
        $guardia = '#if\s*\(\s*!\s*defined\s*\(\s*([\'"])_PS_VERSION_\1\s*\)\s*\)\s*(\{\s*)?(exit|die)\b#i';
        foreach ($this->phps() as $f) {
            if (strtolower(basename($f)) === 'index.php') {
                continue;
            }
            if (!preg_match($guardia, $m->phpSinComentarios($f))) {
                $p->addFile('STR_PS_VERSION_GUARD', $this->msg('STR_PS_VERSION_GUARD'), $f);
            }
        }
        // Ficheros fuera de su sitio (reglas "Expected structure" del oficial)
        $esperada = $m->reglas['Structure']['Expected structure']['App\\Classes\\Entity\\ModuleValidation'];
        $controladores = $this->clasesAdminController();
        foreach ($m->ficheros as $f) {
            if ($m->esIgnorado($f)) {
                continue;
            }
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            $dir = dirname($f);
            $dir = $dir === '\\' || $dir === '.' ? '/' : $dir;
            foreach ($esperada as $patron => $cat) {
                if (!in_array($ext, $cat['extensions'], true)) {
                    continue;
                }
                if (preg_match('#^' . $patron . '(/|$)#i', $dir)) {
                    continue 2;
                }
                if (!empty($cat['exceptions']) && in_array($f, $cat['exceptions'], true)) {
                    continue 2;
                }
                if (!empty($cat['exceptions_callback']) && $dir === '/' && in_array('checkIconController', $cat['exceptions_callback'], true)) {
                    $base = pathinfo($f, PATHINFO_FILENAME);
                    if (in_array(strtolower($base), $controladores, true)) {
                        continue 2;
                    }
                }
                // otra categoría con la misma extensión puede aceptarla (svg en fonts o img)
                $aceptadaPorOtra = false;
                foreach ($esperada as $patron2 => $cat2) {
                    if ($patron2 !== $patron && in_array($ext, $cat2['extensions'], true) && preg_match('#^' . $patron2 . '(/|$)#i', $dir)) {
                        $aceptadaPorOtra = true;
                    }
                }
                if ($aceptadaPorOtra) {
                    continue 2;
                }
                $p->addFile('STR_MISPLACED', $this->msg('STR_MISPLACED', $cat['name'], $patron), $f);
                continue 2;
            }
        }
        // Ficheros innecesarios en producción
        $sobran = array_map('strtolower', $m->reglas['Structure']['Uneeded files on production']);
        $vistos = array();
        foreach (array_merge($m->carpetas, $m->ficheros) as $f) {
            $acum = '';
            foreach (explode('/', trim($f, '/')) as $seg) {
                $acum .= '/' . $seg;
                if (in_array(strtolower($seg), $sobran, true) && !isset($vistos[$acum])) {
                    $vistos[$acum] = true;
                    $p->addFile('STR_UNNEEDED', $this->msg('STR_UNNEEDED'), $acum);
                    break;
                }
            }
        }
    }

    /** Nombres (minúsculas) de clases de controladores admin del módulo: AdminXxx(Controller). */
    private function clasesAdminController(): array
    {
        $out = array();
        foreach ($this->m->phps() as $f) {
            if (stripos($f, '/controllers/admin/') !== 0) {
                continue;
            }
            foreach ($this->nodos($f, Node\Stmt\Class_::class) as $c) {
                if ($c->name) {
                    $n = strtolower($c->name->toString());
                    $out[] = $n;
                    $out[] = preg_replace('#controller$#', '', $n);
                }
            }
        }

        return $out;
    }

    // ===================================================================== Errors

    public function errors(VlPaso $p, string $tmp): void
    {
        $m = $this->m;
        // Sintaxis PHP
        foreach ($this->phps() as $f) {
            foreach ($m->php($f)['errores'] as $e) {
                $l = $e->getStartLine();
                $this->code($p, 'ERR_PHP_PARSE', $f, $l > 0 ? $l : 1, $this->msg('ERR_PHP_PARSE', $e->getRawMessage()));
            }
        }
        // Sintaxis Smarty
        foreach ($this->tpls() as $f) {
            $r = VlSmarty::compilar($m->abs($f), $m->dir, $tmp);
            if ($r) {
                $this->code($p, 'ERR_SMARTY_PARSE', $f, max(1, $r['linea']), $this->msg('ERR_SMARTY_PARSE', $r['mensaje']));
            }
        }
        $principal = $m->ficheroClase ?: $m->principal();
        // install() / uninstall() devuelven valor
        if ($m->clase) {
            foreach (array('install', 'uninstall') as $metodo) {
                $mt = $m->clase->getMethod($metodo);
                if (!$mt || $mt->stmts === null) {
                    continue;
                }
                if (!$this->devuelveValor($mt->stmts)) {
                    $this->code($p, 'ERR_INSTALL_RETURN', $principal, $mt->getStartLine(), $this->msg('ERR_INSTALL_RETURN', $metodo));
                }
            }
        }
        // SQL sin IF (NOT) EXISTS
        $crear = '#(?<!SHOW\s)\bCREATE\s++(TEMPORARY\s++)?TABLE\s++(?!IF\s+NOT\s+EXISTS\b)#i';
        $borrar = '#\bDROP\s++(TEMPORARY\s++)?TABLE\s++(?!IF\s+EXISTS\b)#i';
        foreach ($this->phps() as $f) {
            $vistas = array();
            foreach ($this->cadenas($f) as list($s, $l)) {
                if ((preg_match($crear, $s) || preg_match($borrar, $s)) && !isset($vistas[$l])) {
                    $vistas[$l] = true;
                    $this->code($p, 'ERR_SQL_IF_EXISTS', $f, $l);
                }
            }
        }
        foreach ($m->conExtension(array('sql')) as $f) {
            foreach ($m->lineas($f) as $i => $l) {
                if (preg_match($crear, $l) || preg_match($borrar, $l)) {
                    $this->code($p, 'ERR_SQL_IF_EXISTS', $f, $i + 1);
                }
            }
        }
        // Tipos de imagen a mano: el oficial ya no lo marca en módulos 1.7+ (medido, informe 3785345:
        // getImageLink(..., 'home_default') con compatibilidad 1.7.8 -> Errors en verde). Solo < 1.7.
        $tiposImagen = version_compare($m->compatibilidad()['min'], '1.7', '<') ? $this->phps() : array();
        foreach ($tiposImagen as $f) {
            foreach ($this->nodos($f, Node\Scalar\String_::class) as $s) {
                if (preg_match('#^(small|medium|large|home|cart|thickbox|category|manufacturer|supplier|stores|scene|m_scene)_default$#', $s->value, $mm)) {
                    $this->code($p, 'ERR_IMAGE_TYPE', $f, $s->getStartLine(), $this->msg('ERR_IMAGE_TYPE', $mm[1]));
                }
            }
        }
        // Hooks
        $this->hooks($p);
        // PrestaShop 9 / 9.1
        $this->prestashop9($p);
        // jQuery antiguo
        foreach ($m->conExtension(array('js')) as $f) {
            if ($m->esIgnorado($f)) {
                continue;
            }
            $cab = substr($m->leer($f), 0, 3000);
            $v = null;
            if (preg_match('#jQuery(?: JavaScript Library)? v?([12]\.\d+(?:\.\d+)?)#i', $cab, $mm)) {
                $v = $mm[1];
            } elseif (preg_match('#jquery[-.]([12]\.\d+(?:\.\d+)?)(\.min)?\.js$#i', basename($f), $mm)) {
                $v = $mm[1];
            }
            if ($v !== null) {
                $p->addFile('ERR_OLD_JQUERY', $this->msg('ERR_OLD_JQUERY', $v), $f);
            }
        }
        // _PS_VERSION_ comparado sin version_compare()
        foreach ($this->phps() as $f) {
            foreach ($this->nodos($f, Node\Expr\BinaryOp::class) as $b) {
                if (!($b instanceof Node\Expr\BinaryOp\Smaller || $b instanceof Node\Expr\BinaryOp\SmallerOrEqual
                    || $b instanceof Node\Expr\BinaryOp\Greater || $b instanceof Node\Expr\BinaryOp\GreaterOrEqual)) {
                    continue;
                }
                foreach (array($b->left, $b->right) as $lado) {
                    if ($lado instanceof Node\Expr\ConstFetch && strtoupper($lado->name->toString()) === '_PS_VERSION_') {
                        $this->code($p, 'ERR_PS_VERSION_CONDITION', $f, $b->getStartLine());
                        break;
                    }
                }
            }
        }
        // Comentarios en otro idioma: el oficial NO los marca (medido, informe 3785345: módulo con
        // comentarios en castellano y Errors en verde). Lo que mira es la descripción: STR_ENGLISH.
        // Clase Context (medido, informe 3785354): un aviso por cada línea con Context::
        if ($m->llegaA('8.0')) {
            foreach ($this->phps() as $f) {
                $lineas = array();
                foreach (array(Node\Expr\StaticCall::class, Node\Expr\StaticPropertyFetch::class, Node\Expr\ClassConstFetch::class) as $tipo) {
                    foreach ($this->nodos($f, $tipo) as $n) {
                        if ($n->class instanceof Node\Name && strtolower(ltrim($n->class->toString(), '\\')) === 'context') {
                            $lineas[$n->getStartLine()] = true;
                        }
                    }
                }
                ksort($lineas);
                foreach (array_keys($lineas) as $l) {
                    $this->code($p, 'ERR_CONTEXT', $f, $l);
                }
            }
        }
        // Marcas de conflicto, BOM y codificación
        $regexConflicto = $m->reglas['Conflicts marks regex'];
        $texto = array('php', 'tpl', 'js', 'css', 'sql', 'xml', 'json', 'html', 'htm', 'txt', 'yml', 'yaml', 'twig', 'xlf', 'md', 'csv', 'svg', 'htaccess', 'scss', 'less');
        foreach ($m->ficheros as $f) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (basename($f) === '.htaccess') {
                $ext = 'htaccess';
            }
            if (!in_array($ext, $texto, true) || $m->esIgnorado($f)) {
                continue;
            }
            $c = $m->leer($f);
            if (strncmp($c, "\xEF\xBB\xBF", 3) === 0) {
                $p->addFile('ERR_BOM', $this->msg('ERR_BOM'), $f);
            }
            if ($c !== '' && !mb_check_encoding($c, 'UTF-8')) {
                $p->addFile('ERR_ENCODING', $this->msg('ERR_ENCODING'), $f);
            }
            if ($ext !== 'md') {
                foreach ($m->lineas($f) as $i => $l) {
                    if (preg_match($regexConflicto, $l)) {
                        $this->code($p, 'ERR_CONFLICT_MARKS', $f, $i + 1);
                    }
                }
            }
        }
        if (in_array('/config.xml', $m->ficheros, true)) {
            libxml_use_internal_errors(true);
            $ok = simplexml_load_string($m->leer('/config.xml'));
            $errs = libxml_get_errors();
            libxml_clear_errors();
            if (!$ok) {
                $e = $errs ? $errs[0] : null;
                $this->code($p, 'ERR_CONFIG_XML_PARSE', '/config.xml', $e ? $e->line : 1, $this->msg('ERR_CONFIG_XML_PARSE', $e ? trim($e->message) : 'invalid XML'));
            }
        }
    }

    private function llevaHtml($x): bool
    {
        $partes = array();
        $finder = new NodeFinder();
        foreach (array_merge(array($x), $finder->findInstanceOf(array($x), Node::class)) as $n) {
            if ($n instanceof Node\Scalar\String_ || $n instanceof Node\Scalar\EncapsedStringPart) {
                $partes[] = $n->value;
            }
        }

        return (bool) preg_match('#<\s*/?\s*[a-zA-Z][a-zA-Z0-9]*(\s[^<>]*)?/?>#', implode('', $partes));
    }

    /** ¿Algún return con valor en estas sentencias (sin entrar en closures ni funciones)? */
    private function devuelveValor(array $stmts): bool
    {
        $pila = $stmts;
        while ($pila) {
            $n = array_pop($pila);
            if (!$n instanceof Node) {
                continue;
            }
            if ($n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction || $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\Class_) {
                continue;
            }
            if ($n instanceof Node\Stmt\Return_ && $n->expr !== null) {
                return true;
            }
            foreach ($n->getSubNodeNames() as $sub) {
                $v = $n->$sub;
                if (is_array($v)) {
                    foreach ($v as $vv) {
                        $pila[] = $vv;
                    }
                } elseif ($v instanceof Node) {
                    $pila[] = $v;
                }
            }
        }

        return false;
    }

    /** Hooks registrados o implementados por el módulo: nombre => [fichero, línea]. */
    public function hooksDelModulo(): array
    {
        $out = array();
        foreach ($this->phps() as $f) {
            foreach ($this->nodos($f, Node\Expr\MethodCall::class) as $c) {
                if (!$c->name instanceof Node\Identifier || strtolower($c->name->toString()) !== 'registerhook' || !isset($c->args[0])) {
                    continue;
                }
                $v = $c->args[0]->value;
                $nombres = array();
                if ($v instanceof Node\Scalar\String_) {
                    $nombres[] = $v->value;
                } elseif ($v instanceof Node\Expr\Array_) {
                    foreach ($v->items as $it) {
                        if ($it && $it->value instanceof Node\Scalar\String_) {
                            $nombres[] = $it->value->value;
                        }
                    }
                }
                foreach ($nombres as $n) {
                    $out[strtolower($n)][] = array($n, $f, $c->getStartLine());
                }
            }
        }
        // listas de hooks en arrays de cadenas que acaban en registerHook($hook) dentro de un bucle
        if ($this->m->clase) {
            foreach ($this->m->clase->getMethods() as $mt) {
                $n = $mt->name->toString();
                if (preg_match('#^hook([A-Za-z0-9_]+)$#', $n, $mm)) {
                    $h = lcfirst($mm[1]);
                    $out[strtolower($h)][] = array($h, $this->m->ficheroClase ?: $this->m->principal(), $mt->getStartLine());
                }
            }
        }

        return $out;
    }

    private function hooks(VlPaso $p): void
    {
        $m = $this->m;
        $hooks = $this->hooksDelModulo();
        if (!$hooks) {
            return;
        }
        // Lista de obsoletos de la versión más alta declarada
        $vers = array_keys($m->hooks['deprecated']);
        usort($vers, 'version_compare');
        $ref = end($vers);
        $max = $m->compatibilidad()['max'];
        if ($max !== '99') {
            foreach ($vers as $v) {
                if ($m->llegaA(implode('.', array_slice(explode('.', $v), 0, 2)))) {
                    $ref = $v;
                }
            }
        }
        $deprecados = array_change_key_case($m->hooks['deprecated'][$ref], CASE_LOWER);
        $nombresDep = array();
        foreach ($m->hooks['deprecated'][$ref] as $n => $desde) {
            $nombresDep[strtolower($n)] = $n;
        }
        $quitados = array();
        foreach ($m->hooks['removed_in_9'] as $n) {
            $quitados[strtolower($n)] = $n;
        }
        foreach ($hooks as $lc => $usos) {
            foreach ($usos as list($nombre, $f, $l)) {
                if (isset($deprecados[$lc]) && $m->llegaA(implode('.', array_slice(explode('.', $deprecados[$lc]), 0, 3)))) {
                    $this->code($p, 'ERR_HOOK_DEPRECATED', $f, $l, $this->msg('ERR_HOOK_DEPRECATED', $nombresDep[$lc], $deprecados[$lc]));
                }
                if (isset($quitados[$lc]) && $m->llegaA('9.0')) {
                    $this->code($p, 'ERR_HOOK_REMOVED_9', $f, $l, $this->msg('ERR_HOOK_REMOVED_9', $quitados[$lc]));
                }
                if ($lc === 'displaysearch' && $m->llegaA('9.1')) {
                    $this->code($p, 'ERR_HOOK_DISPLAYSEARCH', $f, $l);
                }
            }
        }
    }

    private function prestashop9(VlPaso $p): void
    {
        $m = $this->m;
        $llega90 = $m->llegaA('9.0');
        $llega91 = $m->llegaA('9.1');
        if (!$llega90) {
            return;
        }
        $libs = array(
            'guzzlehttp\\' => 'GuzzleHttp',
            'swift_' => 'SwiftMailer',
            'swift\\' => 'SwiftMailer',
            'league\\tactician' => 'league/tactician-bundle',
            'sensio\\bundle\\frameworkextrabundle' => 'Symfony FrameworkExtraBundle (sensio/framework-extra-bundle)',
        );
        foreach ($this->phps() as $f) {
            $vistas = array();
            $nombres = array();
            foreach ($this->nodos($f, Node\Name::class) as $n) {
                $nombres[] = array(strtolower(ltrim($n->toString(), '\\')), $n->getStartLine());
            }
            foreach ($nombres as list($n, $l)) {
                foreach ($libs as $pref => $lib) {
                    if (strpos($n, $pref) === 0 && !isset($vistas[$l . $lib])) {
                        $vistas[$l . $lib] = true;
                        $this->code($p, 'ERR_PS9_LIBRARY', $f, $l, $this->msg('ERR_PS9_LIBRARY', $lib));
                    }
                }
                if (strpos($n, 'prestashopbundle\\security\\annotation') === 0 && !isset($vistas[$l . 'ann'])) {
                    $vistas[$l . 'ann'] = true;
                    $this->code($p, 'ERR_PS9_ANNOTATION', $f, $l);
                }
            }
            foreach ($this->nodos($f, Node\Stmt\Class_::class) as $c) {
                if ($c->extends && preg_match('#(^|\\\\)FrameworkBundleAdminController$#i', $c->extends->toString())) {
                    $this->code($p, 'ERR_PS9_ADMIN_CONTROLLER', $f, $c->getStartLine());
                }
            }
            foreach ($this->cadenas($f) as list($s, $l)) {
                foreach (array('PS_LEGACY_IMAGES', 'PS_HIGHT_DPI') as $k) {
                    if (strpos($s, $k) !== false) {
                        $this->code($p, 'ERR_PS9_CONFIGURATION', $f, $l, $this->msg('ERR_PS9_CONFIGURATION', $k));
                    }
                }
            }
        }
        foreach ($this->tpls() as $f) {
            foreach ($m->lineas($f) as $i => $l) {
                foreach (array('PS_LEGACY_IMAGES', 'PS_HIGHT_DPI') as $k) {
                    if (strpos($l, $k) !== false) {
                        $this->code($p, 'ERR_PS9_CONFIGURATION', $f, $i + 1, $this->msg('ERR_PS9_CONFIGURATION', $k));
                    }
                }
            }
        }
        if (!$llega91) {
            return;
        }
        // $HOOK_DISPLAYORDERDETAIL
        foreach (array_merge($this->phps(), $this->tpls()) as $f) {
            foreach ($m->lineas($f) as $i => $l) {
                if (stripos($l, 'HOOK_DISPLAYORDERDETAIL') !== false) {
                    $this->code($p, 'ERR_ORDERDETAIL', $f, $i + 1);
                }
            }
        }
        // Nombre de tema escrito a mano
        foreach ($this->phps() as $f) {
            foreach ($this->cadenas($f) as list($s, $l)) {
                if (preg_match('#themes/(classic|hummingbird)\b#i', $s, $mm)) {
                    $this->code($p, 'ERR_THEME_NAME', $f, $l, $this->msg('ERR_THEME_NAME', $mm[1]));
                }
            }
            foreach ($this->nodos($f, Node\Expr\BinaryOp::class) as $b) {
                if (!($b instanceof Node\Expr\BinaryOp\Equal || $b instanceof Node\Expr\BinaryOp\Identical
                    || $b instanceof Node\Expr\BinaryOp\NotEqual || $b instanceof Node\Expr\BinaryOp\NotIdentical)) {
                    continue;
                }
                foreach (array($b->left, $b->right) as $lado) {
                    if ($lado instanceof Node\Scalar\String_ && in_array(strtolower($lado->value), array('classic', 'hummingbird'), true)) {
                        $this->code($p, 'ERR_THEME_NAME', $f, $b->getStartLine(), $this->msg('ERR_THEME_NAME', $lado->value));
                    }
                }
            }
        }
        foreach ($this->tpls() as $f) {
            foreach ($m->lineas($f) as $i => $l) {
                // ruta del tema o comparación con el nombre ({if $x == 'classic'}); un value="classic" suelto no
                if (preg_match('#themes/(classic|hummingbird)\b|(?:===?|!==?|\beq|\bneq)\s*[\'"](classic|hummingbird)[\'"]|[\'"](classic|hummingbird)[\'"]\s*(?:===?|!==?|\beq\b|\bneq\b)#i', $l, $mm)) {
                    $nombre = '';
                    foreach (array(1, 2, 3) as $k) {
                        if (!empty($mm[$k])) {
                            $nombre = $mm[$k];
                            break;
                        }
                    }
                    $this->code($p, 'ERR_THEME_NAME', $f, $i + 1, $this->msg('ERR_THEME_NAME', $nombre));
                }
            }
        }
    }

    // --------------------------------------------------------------- comentarios en inglés

    const NO_INGLES = array(
        // castellano
        'que', 'para', 'los', 'las', 'del', 'con', 'por', 'una', 'como', 'pero', 'esta', 'este', 'esto', 'cuando',
        'desde', 'hasta', 'sin', 'sobre', 'entre', 'también', 'porque', 'donde', 'muy', 'son', 'hay', 'todo', 'todos',
        'cada', 'el', 'la', 'lo', 'al', 'se', 'es', 'un', 'y', 'o', 'si', 'ya', 'más', 'mas', 'sólo', 'solo', 'tiene',
        'puede', 'hace', 'antes', 'después', 'despues', 'nuevo', 'nueva', 'datos', 'valor', 'guardar', 'obtener',
        'fichero', 'archivo', 'carpeta', 'pedido', 'pedidos', 'cliente', 'clientes', 'producto', 'productos', 'tienda',
        // francés
        'les', 'des', 'est', 'une', 'pour', 'avec', 'dans', 'sur', 'pas', 'qui', 'cette', 'sont', 'aux', 'du', 'au',
        'le', 'et', 'ou', 'mais', 'nous', 'vous', 'ces', 'ses', 'leur',
        // italiano
        'il', 'della', 'delle', 'degli', 'per', 'che', 'sono', 'non', 'gli', 'questo', 'questa',
        // alemán
        'der', 'die', 'das', 'und', 'ist', 'nicht', 'mit', 'für', 'ein', 'eine', 'auf', 'den', 'dem', 'zu', 'wird',
    );

    const INGLES = array(
        'the', 'and', 'of', 'to', 'is', 'in', 'for', 'this', 'that', 'with', 'it', 'be', 'on', 'are', 'if', 'not', 'or',
        'by', 'as', 'from', 'an', 'at', 'we', 'you', 'return', 'returns', 'get', 'set', 'all', 'when', 'will', 'can',
        'should', 'must', 'used', 'use', 'value', 'file', 'module', 'order', 'customer', 'product', 'add', 'list',
    );

    public static function noEsIngles(string $texto): bool
    {
        // fuera etiquetas de phpdoc, URLs, código y emails
        $t = preg_replace('#@\w+[^\n]*#', ' ', $texto);
        $t = preg_replace('#https?://\S+|\S+@\S+|\$\w+|\w+\(\)|`[^`]*`#u', ' ', $t);
        $t = mb_strtolower($t, 'UTF-8');
        preg_match_all('#[\p{L}]+#u', $t, $mm);
        $palabras = $mm[0];
        if (count($palabras) < 3) {
            return false;
        }
        $f = 0;
        $e = 0;
        foreach ($palabras as $w) {
            if (in_array($w, self::INGLES, true)) {
                ++$e;
            } elseif (in_array($w, self::NO_INGLES, true)) {
                ++$f;
            }
        }
        $acentos = (bool) preg_match('#[áéíóúñüçàèìòùâêîôûäëïöœ¿¡]#u', $t);
        if ($acentos && $f >= 1 && $f >= $e) {
            return true;
        }

        return $f >= 2 && $f > $e;
    }

    private function comentariosIngles(VlPaso $p): void
    {
        $m = $this->m;
        foreach ($this->phps() as $f) {
            foreach (@token_get_all($m->leer($f)) as $t) {
                if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) && self::noEsIngles($t[1])) {
                    $this->code($p, 'ERR_ENGLISH_COMMENTS', $f, $t[2]);
                }
            }
        }
        foreach ($this->tpls() as $f) {
            $src = $m->leer($f);
            if (preg_match_all('#\{\*(.*?)\*\}#s', $src, $mm, PREG_OFFSET_CAPTURE)) {
                foreach ($mm[1] as list($txt, $off)) {
                    if (self::noEsIngles($txt)) {
                        $this->code($p, 'ERR_ENGLISH_COMMENTS', $f, substr_count($src, "\n", 0, $off) + 1);
                    }
                }
            }
        }
        foreach ($m->conExtension(array('js', 'css')) as $f) {
            if ($m->esIgnorado($f) || preg_match('#\.min\.(js|css)$#i', $f)) {
                continue;
            }
            $src = $m->leer($f);
            if (preg_match_all('#/\*(.*?)\*/|(?<![:\'"\\\\])//([^\n]*)#s', $src, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                foreach ($mm as $x) {
                    $txt = isset($x[2]) && $x[2][1] >= 0 ? $x[2][0] : $x[1][0];
                    if (self::noEsIngles($txt)) {
                        $this->code($p, 'ERR_ENGLISH_COMMENTS', $f, substr_count($src, "\n", 0, $x[0][1]) + 1);
                    }
                }
            }
        }
    }

    // ===================================================================== Optimizations

    public function optimizations(VlPaso $p): void
    {
        $m = $this->m;
        $libs = array_change_key_case($m->reglas['Libraries defined in PS'], CASE_LOWER);
        foreach ($m->ficheros as $f) {
            $b = strtolower(basename($f));
            if (isset($libs[$b]) && !$m->esIgnorado($f)) {
                $p->addFile('OPT_PS_LIBRARY', $this->msg('OPT_PS_LIBRARY', $libs[$b]['name']), $f, $libs[$b]['included'] ? 'warning' : 'notice');
            }
        }
        // Extensiones PHP (cURL, SOAP) usadas sin comprobar
        $ext = $m->reglas['PHP Extensions'];
        $comprobaciones = array_map('strtolower', $ext['checks']);
        $comprobadas = array();
        $usos = array();
        foreach ($this->phps() as $f) {
            foreach ($this->nodos($f, Node\Expr\FuncCall::class) as $c) {
                $n = self::nombreFuncion($c);
                if ($n === null) {
                    continue;
                }
                if (in_array($n, $comprobaciones, true)) {
                    $txt = '';
                    foreach ($c->args as $a) {
                        $v = VlModulo::valor($a->value);
                        $txt .= is_string($v) ? $v : json_encode($v);
                    }
                    foreach ($ext['triggers'] as $nombreExt => $disparadores) {
                        if (stripos($txt, strtolower($nombreExt)) !== false || stripos($txt, 'curl') !== false && $nombreExt === 'cURL' || stripos($txt, 'soap') !== false && $nombreExt === 'Soap') {
                            $comprobadas[$nombreExt] = true;
                        }
                    }
                    continue;
                }
                foreach ($ext['triggers'] as $nombreExt => $disparadores) {
                    $disp = array_map('strtolower', $disparadores);
                    if (in_array($n, $disp, true) || ($nombreExt === 'cURL' && strpos($n, 'curl_') === 0)) {
                        $usos[$nombreExt][$f][] = $c->getStartLine();
                    }
                }
            }
            foreach ($this->nodos($f, Node\Expr\New_::class) as $nw) {
                if ($nw->class instanceof Node\Name && strtolower(ltrim($nw->class->toString(), '\\')) === 'soapclient') {
                    $usos['Soap'][$f][] = $nw->getStartLine();
                }
            }
        }
        foreach ($usos as $nombreExt => $porFichero) {
            if (!empty($comprobadas[$nombreExt])) {
                continue;
            }
            $sugerencia = $nombreExt === 'cURL' ? "function_exists('curl_init')" : "extension_loaded('soap')";
            foreach ($porFichero as $f => $lineas) {
                $this->code($p, 'OPT_PHP_EXTENSION', $f, $lineas[0], $this->msg('OPT_PHP_EXTENSION', $nombreExt, $sugerencia));
            }
        }
        // Ficheros vacíos
        foreach ($m->ficheros as $f) {
            if ($m->esIgnorado($f)) {
                continue;
            }
            if (trim($m->leer($f)) === '') {
                $p->addFile('OPT_EMPTY_FILE', $this->msg('OPT_EMPTY_FILE'), $f);
            }
        }
        // HTML en el PHP principal (medido, informe 3785354): cada línea de una cadena con
        // <div, <p, <span o <h1..<h6. Los demás ficheros PHP no se miran (un <div> en classes/ no salió).
        $principal = $m->ficheroClase ?: $m->principal();
        if (in_array($principal, $m->ficheros, true)) {
            foreach (self::lineasConHtml($m->leer($principal)) as $l) {
                $this->code($p, 'OPT_HTML_IN_PHP', $principal, $l);
            }
        }
    }

    /**
     * Pestaña Compatibility, parte del «PS Guide» del oficial: llamadas estáticas a métodos (y uso de
     * clases) marcados @deprecated en el núcleo, si la marca cae dentro del rango declarado. Es NOTA.
     */
    public function deprecados(VlPaso $p): void
    {
        $m = $this->m;
        if (!$m->deprecados) {
            return;
        }
        // Versión de referencia: la más alta del rango declarado (la misma que usa PHPStan). Una marca
        // cuenta solo si sigue puesta en esa versión (Module::isInstalled lo estaba en 1.7.8 y ya no).
        $c0 = $m->compatibilidad();
        $ref = null;
        foreach (array('1.7.8.11', '8.1.7', '8.2.7', '9.1.5', '9.2.0') as $v) {
            if (version_compare($v, $c0['min'], '<')) {
                continue;
            }
            if ($c0['max'] !== '99') {
                $pm = explode('.', $c0['max']);
                if (version_compare(implode('.', array_slice(explode('.', $v), 0, count($pm))), $c0['max'], '>')) {
                    continue;
                }
            }
            $ref = $v;
        }
        if ($ref === null) {
            return;
        }
        foreach ($this->phps() as $f) {
            foreach ($this->nodos($f, Node\Expr\StaticCall::class) as $c) {
                if (!$c->class instanceof Node\Name || !$c->name instanceof Node\Identifier) {
                    continue;
                }
                $clase = strtolower(ltrim($c->class->toString(), '\\'));
                if (in_array($clase, array('self', 'static', 'parent'), true)) {
                    continue;
                }
                $clase = preg_replace('#core$#', '', $clase);
                $k = $clase . '::' . strtolower($c->name->toString());
                $d = $m->deprecados[$k] ?? null;
                if ($d && !in_array($ref, $d['en'] ?? array(), true)) {
                    $d = null;
                }
                if ($d && $m->llegaA(implode('.', array_slice(explode('.', $d['desde']), 0, 2)))) {
                    $this->code($p, 'CMP_DEPRECATED', $f, $c->getStartLine(), sprintf($this->g('CMP_DEPRECATED')['mensaje'], $d['nombre'], $d['desde']));
                }
            }
            foreach ($this->nodos($f, Node\Expr\New_::class) as $nw) {
                if (!$nw->class instanceof Node\Name) {
                    continue;
                }
                $k = preg_replace('#core$#', '', strtolower(ltrim($nw->class->toString(), '\\')));
                $d = $m->deprecados[$k] ?? null;
                if ($d && !in_array($ref, $d['en'] ?? array(), true)) {
                    $d = null;
                }
                if ($d && $d['tipo'] === 'clase' && $m->llegaA(implode('.', array_slice(explode('.', $d['desde']), 0, 2)))) {
                    $this->code($p, 'CMP_DEPRECATED', $f, $nw->getStartLine(), "Class '" . $d['nombre'] . "' is deprecated from PrestaShop " . $d['desde']);
                }
            }
        }
    }

    /** Líneas de cadenas PHP (o HTML suelto) con una etiqueta de bloque div, p, span o h1-h6. */
    public static function lineasConHtml(string $codigo): array
    {
        $lineas = array();
        foreach (@token_get_all($codigo) as $t) {
            if (!is_array($t) || !in_array($t[0], array(T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML), true)) {
                continue;
            }
            foreach (preg_split("/\r\n|\n|\r/", $t[1]) as $i => $trozo) {
                if (preg_match('#<(div|p|span|h[1-6])(\s|>|/)#i', $trozo)) {
                    $lineas[$t[2] + $i] = true;
                }
            }
        }
        ksort($lineas);

        return array_keys($lineas);
    }

    // ===================================================================== Translations

    public function translations(VlPaso $p): void
    {
        $m = $this->m;
        foreach ($this->phps() as $f) {
            foreach ($this->nodos($f, Node\Expr\MethodCall::class) as $c) {
                if (!$c->name instanceof Node\Identifier) {
                    continue;
                }
                $n = strtolower($c->name->toString());
                if ($n !== 'l' || !isset($c->args[0])) {
                    continue;
                }
                $a = $c->args[0]->value;
                if ($a instanceof Node\Scalar\String_) {
                    if ($a->getAttribute('kind') === Node\Scalar\String_::KIND_DOUBLE_QUOTED) {
                        $this->code($p, 'TRA_QUOTES_PHP', $f, $c->getStartLine());
                    }
                } elseif ($a instanceof Node\Scalar\Encapsed) {
                    $this->code($p, 'TRA_QUOTES_PHP', $f, $c->getStartLine());
                    $this->code($p, 'TRA_LITERAL_PHP', $f, $c->getStartLine());
                } else {
                    $this->code($p, 'TRA_LITERAL_PHP', $f, $c->getStartLine());
                }
            }
        }
        foreach ($this->tpls() as $f) {
            foreach (VlSmarty::etiquetas($m->leer($f)) as $t) {
                if (!preg_match('#^l\s#', $t['tag'])) {
                    continue;
                }
                if (preg_match('#\bs\s*=\s*"#', $t['tag'])) {
                    $this->code($p, 'TRA_QUOTES_TPL', $f, $t['linea']);
                }
                $tieneD = (bool) preg_match('#\bd\s*=#', $t['tag']);
                if (preg_match('#\bmod\s*=\s*([\'"])([^\'"]*)\1#', $t['tag'], $mm)) {
                    if ($mm[2] !== $m->carpeta) {
                        $this->code($p, 'TRA_MOD_TPL', $f, $t['linea'], $this->msg('TRA_MOD_TPL', $m->carpeta, ucfirst(str_replace('_', '', $m->carpeta))));
                    }
                } elseif (!$tieneD) {
                    $this->code($p, 'TRA_MOD_TPL', $f, $t['linea'], $this->msg('TRA_MOD_TPL', $m->carpeta, ucfirst(str_replace('_', '', $m->carpeta))));
                }
            }
        }
    }

    // ===================================================================== Licenses

    public function licenses(VlPaso $p): void
    {
        $m = $this->m;
        $regex = $m->reglas['Licenses']['Copyright regex'];
        $regexAnyo = str_replace('<year>', date('y'), $m->reglas['Licenses']['Copyright year regex']);
        // Medido contra el oficial (informe 3785345): en PHP exige un bloque de documentación de
        // fichero (/** */ justo tras <?php) y lo dice en la línea 1; en JS y TPL, una cabecera de
        // licencia (regex de /rules); los CSS no los mira.
        foreach ($m->conExtension(array('php', 'tpl', 'js')) as $f) {
            // los index.php SÍ se miran aquí (el oficial les pide el bloque de fichero): solo cuenta la carpeta
            if ($m->esIgnorado(dirname($f))) {
                continue;
            }
            $c = $m->leer($f);
            if (trim($c) === '') {
                continue;
            }
            $cab = substr($c, 0, 4000);
            if (strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'php') {
                // Medido (3785345): los index.php de views/css y views/js no salen; los de las demás
                // carpetas, sí. Las carpetas de recursos (css, js, img, fonts) no se miran.
                // Medido (3785354): translations/index.php y translations/es-ES/index.php tampoco salen.
                if (preg_match('#^(/views/(css|js|img|fonts)|/translations)(/|$)#i', dirname($f))) {
                    continue;
                }
                $estado = self::docDeFichero($c);
                if ($estado === 'falta' || $estado === 'estilo') {
                    $txt = $estado === 'estilo' ? 'You must use "/**" style comments for a file comment' : $this->msg('LIC_FILE_DOC');
                    $p->addCode('LIC_FILE_DOC', $f, $txt, 1, $m->linea($f, 1), $m->bloque($f, 1, 3));
                    continue;
                }
                if ($estado === 'hueco') {
                    $p->addCode('LIC_FILE_DOC_SPACING', $f, $this->msg('LIC_FILE_DOC_SPACING'), 1, $m->linea($f, 1), $m->bloque($f, 1, 3));
                    continue;
                }
            } elseif (!preg_match($regex . 'm', $cab)) {
                $p->addFile('LIC_HEADER', $this->msg('LIC_HEADER'), $f);
                continue;
            }
            if (preg_match($regexAnyo, $cab, $mm)) {
                $p->addFile('LIC_YEAR', $this->msg('LIC_YEAR', trim(substr($mm[0], 0, 9))), $f);
            }
            if (preg_match('#@license\s+([^\n]*)#i', $cab, $mm)) {
                $lic = trim(preg_replace('#\*/.*$#', '', $mm[1]));
                if (!preg_match('#AFL|Academic Free|OSL|Open Software|MIT|BSD|Apache|ISC|EUPL|CC0|CC-BY|Creative Commons#i', $lic)) {
                    $p->addFile('LIC_TYPE', $this->msg('LIC_TYPE', $lic), $f);
                }
            }
        }
    }

    /**
     * Bloque de documentación de fichero, como Squiz.Commenting.FileComment de PHPCS (sus mensajes
     * son los que da el oficial): 'ok', 'falta' (no hay, o es el de una clase/función),
     * 'estilo' (comentario /* o // en vez de /**) o 'hueco' (líneas en blanco entre <?php y el bloque).
     */
    public static function docDeFichero(string $codigo): string
    {
        $tokens = @token_get_all($codigo);
        $n = count($tokens);
        $abre = null;
        for ($i = 0; $i < $n; ++$i) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_OPEN_TAG) {
                $abre = $i;
                break;
            }
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_INLINE_HTML || trim($tokens[$i][1]) !== '') {
                return 'falta';
            }
        }
        if ($abre === null) {
            return 'falta';
        }
        $sig = function ($desde) use ($tokens, $n) {
            for ($j = $desde; $j < $n; ++$j) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }

                return $j;
            }

            return null;
        };
        $c = $sig($abre + 1);
        if ($c === null || !is_array($tokens[$c])) {
            return 'falta';
        }
        if ($tokens[$c][0] === T_COMMENT) {
            return strpos($tokens[$c][1], '/*') === 0 ? 'estilo' : 'falta';
        }
        if ($tokens[$c][0] !== T_DOC_COMMENT) {
            return 'falta';
        }
        // si lo que viene detrás es una clase o una función, el bloque es de ella, no del fichero
        $tras = $sig($c + 1);
        $ignorar = array(T_CLASS, T_INTERFACE, T_TRAIT, T_FUNCTION, T_PUBLIC, T_PRIVATE, T_PROTECTED, T_FINAL,
            T_STATIC, T_ABSTRACT, T_CONST, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE);
        if (defined('T_ENUM')) {
            $ignorar[] = T_ENUM;
        }
        if ($tras !== null && is_array($tokens[$tras]) && in_array($tokens[$tras][0], $ignorar, true)) {
            return 'falta';
        }
        // línea de <?php: el token de apertura incluye su salto de línea
        $lineaAbre = $tokens[$abre][2];
        if ($tokens[$c][2] > $lineaAbre + 1) {
            return 'hueco';
        }

        return 'ok';
    }

    // ===================================================================== Security

    public function security(VlPaso $p): void
    {
        $m = $this->m;
        if (!in_array('/.htaccess', $m->ficheros, true)) {
            $p->addFile('SEC_HTACCESS', $this->msg('SEC_HTACCESS'), '/.htaccess');
        }
        foreach (array_merge(array(''), $m->carpetas) as $d) {
            if ($d !== '' && $m->esIgnorado($d)) {
                continue;
            }
            if (!in_array($d . '/index.php', $m->ficheros, true)) {
                $p->addFile('SEC_INDEX_PHP', $this->msg('SEC_INDEX_PHP'), $d . '/index.php');
            }
        }
        // Funciones y variables prohibidas
        $prohibidas = array();
        foreach ($m->reglas['Disallowed functions & methods'] as $r) {
            $prohibidas[strtolower(str_replace("\u{200b}", '', $r['name']))] = $r;
        }
        $variables = array();
        foreach ($m->reglas['Disallowed variables'] as $v => $alt) {
            if (preg_match('#^\$(_[A-Z]+)$#', $v, $mm)) {
                $variables[$mm[1]] = $alt;
            }
        }
        foreach ($this->phps() as $f) {
            $esIndex = strtolower(basename($f)) === 'index.php';
            foreach ($this->nodos($f, Node\Expr\FuncCall::class) as $c) {
                $n = self::nombreFuncion($c);
                if ($n === null) {
                    continue;
                }
                if (in_array($n, array('serialize', 'unserialize'), true)) {
                    if (stripos($f, '/upgrade/') !== 0) {
                        $this->code($p, 'SEC_SERIALIZE', $f, $c->getStartLine());
                    }
                    continue;
                }
                if (!isset($prohibidas[$n])) {
                    continue;
                }
                $r = $prohibidas[$n];
                if (!empty($r['excludes']) && in_array(basename($f), $r['excludes'], true)) {
                    continue;
                }
                if (!empty($r['needles'])) {
                    $txt = '';
                    foreach ($c->args as $a) {
                        $v = VlModulo::valor($a->value);
                        $txt .= is_string($v) ? $v : '';
                    }
                    $hay = false;
                    foreach ($r['needles'] as $needle) {
                        if (stripos($txt, $needle) !== false) {
                            $hay = true;
                        }
                    }
                    if (!$hay) {
                        continue;
                    }
                }
                $this->code($p, 'SEC_FUNCTION', $f, $c->getStartLine(), self::textoProhibida($n, $r));
            }
            foreach ($this->nodos($f, Node\Expr\Eval_::class) as $e) {
                $this->code($p, 'SEC_FUNCTION', $f, $e->getStartLine(), self::textoProhibida('eval', array('name' => 'eval')));
            }
            // Escribir en $_GET['x'] = ... no lo marca el oficial (medido, informe 3785345): solo se
            // cuentan las lecturas. Se apartan las variables que son el destino de una asignación.
            $escritas = array();
            foreach ($this->nodos($f, Node\Expr\Assign::class) as $as) {
                $dest = $as->var;
                while ($dest instanceof Node\Expr\ArrayDimFetch) {
                    $dest = $dest->var;
                }
                if ($dest instanceof Node\Expr\Variable) {
                    $escritas[spl_object_id($dest)] = true;
                }
            }
            foreach ($this->nodos($f, Node\Expr\Variable::class) as $v) {
                if (isset($escritas[spl_object_id($v)])) {
                    continue;
                }
                $nombre = null;
                if (is_string($v->name)) {
                    $nombre = $v->name;
                } elseif ($v->name instanceof Node\Scalar\String_) {
                    $nombre = $v->name->value;
                }
                if ($nombre === null) {
                    continue;
                }
                if ($nombre === 'GLOBALS') {
                    $this->code($p, 'SEC_GLOBALS', $f, $v->getStartLine());
                    continue;
                }
                if (array_key_exists($nombre, $variables)) {
                    $alt = $variables[$nombre];
                    $texto = 'The use of $' . $nombre . ' is forbidden' . ($alt ? '; use ' . $alt . ' instead' : '') . '.';
                    $this->code($p, 'SEC_VARIABLE', $f, $v->getStartLine(), $texto);
                }
            }
            foreach ($this->nodos($f, Node\Stmt\Global_::class) as $gb) {
                $this->code($p, 'SEC_GLOBALS', $f, $gb->getStartLine());
            }
            unset($esIndex);
        }
        // Smarty
        $regSm = $m->reglas['Smarty'];
        $seguras = array_map('strtolower', $regSm['Expected escapes']);
        $tipos = array_map('strtolower', $regSm['Expected modifiers']);
        foreach ($this->tpls() as $f) {
            foreach (VlSmarty::etiquetas($m->leer($f)) as $t) {
                $info = VlSmarty::modificadores($t['tag']);
                foreach ($info['mods'] as $md) {
                    if ($md['nombre'] === 'strval') {
                        $this->code($p, 'SEC_SMARTY_STRVAL', $f, $t['linea']);
                    }
                }
                if (!VlSmarty::esSalida($t['tag'])) {
                    continue;
                }
                if ($info['nofilter']) {
                    $this->code($p, 'SEC_SMARTY_NOFILTER', $f, $t['linea']);
                    continue;
                }
                $estado = 'sin';
                foreach ($info['mods'] as $md) {
                    if (in_array($md['nombre'], $seguras, true)) {
                        $estado = 'ok';
                        break;
                    }
                    if ($md['nombre'] === 'escape') {
                        $tipo = strtolower($md['params'][0] ?? '');
                        $cs = strtolower($md['params'][1] ?? '');
                        if ($tipo === '' || !in_array($tipo, $tipos, true)) {
                            $estado = 'tipo';
                        } elseif ($cs !== 'utf-8') {
                            $estado = 'utf8';
                        } else {
                            $estado = 'ok';
                            break;
                        }
                    }
                }
                if ($estado === 'ok') {
                    continue;
                }
                $expr = '{' . $t['tag'] . '}';
                if ($estado === 'utf8') {
                    $texto = "The 'UTF-8' parameter is missing in the escape modifier: " . $expr;
                } elseif ($estado === 'tipo') {
                    $texto = 'The escape modifier must have a valid type (' . implode(', ', $regSm['Expected modifiers']) . ") and 'UTF-8': " . $expr;
                } else {
                    $texto = "Smarty variable not escaped, add |escape:'htmlall':'UTF-8' (or one of " . implode(', ', $regSm['Expected escapes']) . '): ' . $expr;
                }
                $this->code($p, 'SEC_SMARTY_ESCAPE', $f, $t['linea'], $texto);
            }
        }
    }

    private static function textoProhibida(string $n, array $r): string
    {
        $t = 'The function ' . $n . '() is forbidden';
        if (!empty($r['error_message'])) {
            $t .= ', use ' . $r['error_message'] . ' instead';
        } elseif (!empty($r['replace_with'])) {
            $t .= ', use ' . $r['replace_with'] . ' instead';
        }

        return $t . '.';
    }
}
