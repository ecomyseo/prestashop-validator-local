<?php
/**
 * Módulo a validar: carpeta o ZIP, lista de ficheros, PHP analizados y datos de identidad.
 */

use PhpParser\Error as PhpParserError;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

class VlModulo
{
    /** @var string carpeta raíz del módulo (absoluta, con barras /) */
    public $dir;
    /** @var string nombre de la carpeta */
    public $carpeta;
    /** @var string|null ruta del ZIP si la entrada era un ZIP */
    public $zip;
    /** @var array entradas de primer nivel del ZIP (carpetas y ficheros) */
    public $zipRaiz = array();
    /** @var array reglas oficiales (reglas/rules.json) */
    public $reglas;
    /** @var array */
    public $hooks;
    /** @var array métodos y clases @deprecated del núcleo (reglas/deprecados.json) */
    public $deprecados = array();
    /** @var string[] ficheros relativos "/a/b.php" (sin vendor/) */
    public $ficheros = array();
    /** @var string[] todos los ficheros, vendor incluido */
    public $todos = array();
    /** @var string[] carpetas relativas "/a/b" (sin vendor/) */
    public $carpetas = array();
    /** @var array<string, array{ast: ?array, errores: PhpParserError[]}> */
    private $php = array();
    /** @var array<string,string> */
    private $contenido = array();
    /** @var array identidad leída del constructor */
    public $id = array();
    /** @var string|null */
    public $claseNombre;
    /** @var Node\Stmt\Class_|null */
    public $clase;
    /** @var string|null */
    public $claseExtiende;
    /** @var bool */
    public $llamaParent = false;
    /** @var bool la clase principal no está en <carpeta>/<carpeta>.php */
    public $claseFueraDeSitio = false;
    /** @var string */
    public $tmp;

    public function __construct(string $ruta, string $raizHerramienta)
    {
        $this->reglas = json_decode(file_get_contents($raizHerramienta . '/reglas/rules.json'), true)['rules'];
        $this->hooks = json_decode(file_get_contents($raizHerramienta . '/reglas/hooks.json'), true);
        $dep = @file_get_contents($raizHerramienta . '/reglas/deprecados.json');
        $this->deprecados = $dep ? (json_decode($dep, true)['marcas'] ?? array()) : array();
        $ruta = rtrim(str_replace('\\', '/', $ruta), '/');
        if (is_file($ruta) && strtolower(pathinfo($ruta, PATHINFO_EXTENSION)) === 'zip') {
            $this->zip = $ruta;
            $this->tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/vl_' . substr(md5($ruta . microtime(true)), 0, 10);
            @mkdir($this->tmp, 0777, true);
            $z = new ZipArchive();
            if ($z->open($ruta) !== true) {
                throw new RuntimeException('No se puede abrir el ZIP: ' . $ruta);
            }
            $raiz = array();
            for ($i = 0; $i < $z->numFiles; ++$i) {
                $n = str_replace('\\', '/', $z->getNameIndex($i));
                $primero = explode('/', ltrim($n, '/'))[0];
                if ($primero !== '') {
                    $raiz[$primero] = true;
                }
            }
            $this->zipRaiz = array_keys($raiz);
            $z->extractTo($this->tmp);
            $z->close();
            $carpetas = array_values(array_filter($this->zipRaiz, function ($e) {
                return is_dir($this->tmp . '/' . $e) && !in_array($e, array('__MACOSX'), true);
            }));
            $this->dir = count($carpetas) >= 1 ? $this->tmp . '/' . $carpetas[0] : $this->tmp;
        } elseif (is_dir($ruta)) {
            $this->dir = realpath($ruta) ? str_replace('\\', '/', realpath($ruta)) : $ruta;
        } else {
            throw new RuntimeException('No existe la carpeta o el ZIP: ' . $ruta);
        }
        $this->carpeta = basename($this->dir);
        $this->listar();
        $this->leerIdentidad();
    }

    public function __destruct()
    {
        if ($this->tmp && is_dir($this->tmp)) {
            self::borrarArbol($this->tmp);
        }
    }

    public static function borrarArbol(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($d);
    }

    private function listar(): void
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $base = strlen($this->dir);
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), $base));
            if ($f->isDir()) {
                if (!self::esVendor($rel)) {
                    $this->carpetas[] = $rel;
                }
                continue;
            }
            $this->todos[] = $rel;
            if (!self::esVendor($rel)) {
                $this->ficheros[] = $rel;
            }
        }
        sort($this->ficheros);
        sort($this->carpetas);
        sort($this->todos);
    }

    /** vendor/ lo gestiona Composer: el oficial lo excluye de las comprobaciones (5.1.3). */
    public static function esVendor(string $rel): bool
    {
        return (bool) preg_match('#^/vendor(/|$)#i', $rel);
    }

    /** ¿Está bajo una carpeta "ignorada" según las reglas oficiales (docs, .git, ...)? */
    public function esIgnorado(string $rel): bool
    {
        $ign = array_map('strtolower', $this->reglas['Structure']['Ignored folders']);
        foreach (explode('/', trim($rel, '/')) as $seg) {
            if (in_array(strtolower($seg), $ign, true)) {
                return true;
            }
        }

        return self::esVendor($rel);
    }

    public function abs(string $rel): string
    {
        return $this->dir . $rel;
    }

    public function leer(string $rel): string
    {
        if (!isset($this->contenido[$rel])) {
            $c = @file_get_contents($this->abs($rel));
            $this->contenido[$rel] = $c === false ? '' : $c;
        }

        return $this->contenido[$rel];
    }

    public function lineas(string $rel): array
    {
        return preg_split("/\r\n|\n|\r/", $this->leer($rel));
    }

    public function linea(string $rel, int $n): string
    {
        $l = $this->lineas($rel);

        return isset($l[$n - 1]) ? rtrim($l[$n - 1]) : '';
    }

    public function bloque(string $rel, int $n, int $margen = 2): string
    {
        $l = $this->lineas($rel);
        $ini = max(1, $n - $margen);
        $fin = min(count($l), $n + $margen);
        $out = array();
        for ($i = $ini; $i <= $fin; ++$i) {
            $out[] = $l[$i - 1];
        }

        return implode("\n", $out);
    }

    /** @return string[] ficheros (sin vendor) con alguna de esas extensiones */
    public function conExtension(array $ext): array
    {
        $ext = array_map('strtolower', $ext);

        return array_values(array_filter($this->ficheros, function ($f) use ($ext) {
            return in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $ext, true);
        }));
    }

    public function phps(): array
    {
        return $this->conExtension(array('php'));
    }

    /**
     * @return array{ast: ?array, errores: PhpParserError[]}
     */
    public function php(string $rel): array
    {
        if (!isset($this->php[$rel])) {
            $factory = new ParserFactory();
            $parser = $factory->create(ParserFactory::PREFER_PHP7);
            $errores = new Collecting();
            $ast = null;
            try {
                $ast = $parser->parse($this->leer($rel), $errores);
            } catch (PhpParserError $e) {
                $errores->handleError($e);
            }
            $this->php[$rel] = array('ast' => $ast, 'errores' => $errores->getErrors());
        }

        return $this->php[$rel];
    }

    /** @return Node[] */
    public function buscar(string $rel, string $clase): array
    {
        $p = $this->php($rel);
        if (!$p['ast']) {
            return array();
        }

        return (new NodeFinder())->findInstanceOf($p['ast'], $clase);
    }

    /** @var string|null fichero donde está de verdad la clase principal */
    public $ficheroClase;

    public function principal(): string
    {
        return '/' . $this->carpeta . '.php';
    }

    private function leerIdentidad(): void
    {
        $rel = $this->principal();
        if (in_array($rel, $this->ficheros, true)) {
            foreach ($this->buscar($rel, Node\Stmt\Class_::class) as $c) {
                if ($c->name && strtolower($c->name->toString()) === strtolower($this->carpeta)) {
                    $this->clase = $c;
                    break;
                }
            }
        }
        // Si la carpeta no se llama como el módulo, la clase principal se busca en los PHP de la
        // raíz: la que extiende Module, PaymentModule o CarrierModule (para poder decir cuál es).
        if (!$this->clase) {
            $extiende = array_map('strtolower', $this->reglas['Possible class extends']);
            foreach ($this->ficheros as $f) {
                if (substr_count($f, '/') !== 1 || strtolower(pathinfo($f, PATHINFO_EXTENSION)) !== 'php' || basename($f) === 'index.php') {
                    continue;
                }
                foreach ($this->buscar($f, Node\Stmt\Class_::class) as $c) {
                    if ($c->name && $c->extends && in_array(strtolower(basename(str_replace('\\', '/', $c->extends->toString()))), $extiende, true)) {
                        $this->clase = $c;
                        $rel = $f;
                        $this->claseFueraDeSitio = true;
                        break 2;
                    }
                }
            }
        }
        if (!$this->clase) {
            return;
        }
        $this->ficheroClase = $rel;
        $this->claseNombre = $this->clase->name->toString();
        $this->claseExtiende = $this->clase->extends ? ltrim($this->clase->extends->toString(), '\\') : null;
        $ctor = $this->clase->getMethod('__construct');
        if (!$ctor || !$ctor->stmts) {
            return;
        }
        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($ctor->stmts, Node\Expr\Assign::class) as $a) {
            if ($a->var instanceof Node\Expr\PropertyFetch && $a->var->var instanceof Node\Expr\Variable
                && $a->var->var->name === 'this' && $a->var->name instanceof Node\Identifier) {
                $this->id[$a->var->name->toString()] = self::valor($a->expr);
                $this->id['__linea_' . $a->var->name->toString()] = $a->getStartLine();
            }
        }
        foreach ($finder->findInstanceOf($ctor->stmts, Node\Expr\StaticCall::class) as $s) {
            if ($s->class instanceof Node\Name && strtolower($s->class->toString()) === 'parent'
                && $s->name instanceof Node\Identifier && strtolower($s->name->toString()) === '__construct') {
                $this->llamaParent = true;
            }
        }
    }

    /** Valor "estático" de una expresión: cadena, número, constante, array o llamada a l()/trans(). */
    public static function valor($e)
    {
        if ($e instanceof Node\Scalar\String_ || $e instanceof Node\Scalar\LNumber || $e instanceof Node\Scalar\DNumber) {
            return $e->value;
        }
        if ($e instanceof Node\Expr\ConstFetch) {
            $n = $e->name->toString();
            if (in_array(strtolower($n), array('true', 'false', 'null'), true)) {
                return strtolower($n) === 'true' ? true : (strtolower($n) === 'false' ? false : null);
            }

            return array('__const' => $n);
        }
        if ($e instanceof Node\Expr\Array_) {
            $out = array();
            foreach ($e->items as $it) {
                if (!$it) {
                    continue;
                }
                $v = self::valor($it->value);
                if ($it->key !== null) {
                    $k = self::valor($it->key);
                    $out[is_scalar($k) ? (string) $k : count($out)] = $v;
                } else {
                    $out[] = $v;
                }
            }

            return $out;
        }
        if ($e instanceof Node\Expr\MethodCall && $e->name instanceof Node\Identifier
            && in_array(strtolower($e->name->toString()), array('l', 'trans'), true) && isset($e->args[0])) {
            return self::valor($e->args[0]->value);
        }
        if ($e instanceof Node\Expr\BinaryOp\Concat) {
            $a = self::valor($e->left);
            $b = self::valor($e->right);

            return (is_string($a) ? $a : '') . (is_string($b) ? $b : '');
        }

        return array('__expr' => get_class($e));
    }

    /**
     * Rango de compatibilidad declarado, normalizado a cadenas "x.y.z.w".
     * max = _PS_VERSION_ se toma como "cualquier versión futura" (99).
     */
    public function compatibilidad(): array
    {
        $c = $this->id['ps_versions_compliancy'] ?? null;
        $min = '1.6';
        $max = '99';
        if (is_array($c)) {
            if (isset($c['min']) && is_string($c['min'])) {
                $min = $c['min'];
            }
            if (isset($c['max'])) {
                $max = is_string($c['max']) ? $c['max'] : '99';
            }
        }

        return array('min' => $min, 'max' => $max, 'declarado' => is_array($c));
    }

    /** ¿El rango declarado llega a la versión $v (p. ej. '9.0' o '9.1')? */
    public function llegaA(string $v): bool
    {
        $max = $this->compatibilidad()['max'];
        if ($max === '99') {
            return true;
        }
        // '9.0' declarado como max cubre todo 9.0.x: se compara con los mismos componentes que $v
        $pv = explode('.', $v);
        $pm = array_slice(array_pad(explode('.', $max), count($pv), '0'), 0, count($pv));

        return version_compare(implode('.', $pm), implode('.', $pv), '>=');
    }

    /** Comentarios y cadenas fuera: código PHP sin comentarios (para regex de estructura). */
    public function phpSinComentarios(string $rel): string
    {
        $out = '';
        foreach (@token_get_all($this->leer($rel)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($t[1], "\n"));
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }

        return $out;
    }
}
