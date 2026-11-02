<?php
/**
 * Resultado de un paso (pestaña) con la MISMA forma JSON que devuelve el validator oficial
 * en /module/<id>/validate/<paso> (leído de su validation.js):
 *
 *   files         { "<mensaje>": ["/ruta", ...] }
 *   readMoreLinks { "<mensaje>": {"Documentation": "url"} }
 *   code          { "/fichero": { "messages": { "<mensaje>": { "type": "Error", "content": [ {line, content, block, readMoreLinks} ] } } } }
 *   types         { error, warning, notice, files, code }
 *
 * Además lleva, por goal, cuántas veces ha saltado (para el informe de goals).
 */
class VlPaso
{
    /** @var string */
    public $id;
    /** @var array */
    public $files = array();
    /** @var array */
    public $readMoreLinks = array();
    /** @var array */
    public $code = array();
    /** @var array */
    public $types = array('error' => 0, 'warning' => 0, 'notice' => 0, 'files' => 0, 'code' => 0);
    /** @var array<string,int> */
    public $goals = array();
    /** @var array<string,array> muestras por goal */
    public $muestras = array();
    /** @var string|null */
    public $fallo;
    /** @var array */
    public $avisosInternos = array();
    /** @var bool */
    public $omitido = false;

    public function __construct(string $id)
    {
        $this->id = $id;
        foreach (VlGoals::delPaso($id) as $g) {
            $this->goals[$g['id']] = 0;
        }
    }

    private static function tipo(string $t): string
    {
        return strtolower($t);
    }

    /**
     * Resultado a nivel de fichero (lista de rutas bajo un mensaje).
     */
    public function addFile(string $goal, string $mensaje, string $ruta, ?string $tipo = null, ?string $link = null): void
    {
        $g = VlGoals::get($goal);
        $tipo = self::tipo($tipo ?: $g['tipo']);
        if (!isset($this->files[$mensaje])) {
            $this->files[$mensaje] = array();
        }
        if (in_array($ruta, $this->files[$mensaje], true)) {
            return;
        }
        $this->files[$mensaje][] = $ruta;
        $link = $link ?: $g['link'];
        if ($link) {
            $this->readMoreLinks[$mensaje] = array('Documentation' => $link);
        }
        $this->types[$tipo]++;
        $this->types['files']++;
        $this->goals[$goal] = ($this->goals[$goal] ?? 0) + 1;
        $this->muestras[$goal][] = $ruta;
    }

    /**
     * Resultado a nivel de código (fichero + línea).
     */
    public function addCode(string $goal, string $fichero, string $mensaje, int $linea, string $contenido = '', string $bloque = '', ?string $tipo = null, ?string $link = null): void
    {
        $g = VlGoals::get($goal);
        $tipo = self::tipo($tipo ?: $g['tipo']);
        if (!isset($this->code[$fichero])) {
            $this->code[$fichero] = array('messages' => array());
        }
        if (!isset($this->code[$fichero]['messages'][$mensaje])) {
            $this->code[$fichero]['messages'][$mensaje] = array('type' => ucfirst($tipo), 'content' => array());
        }
        $link = $link ?: $g['link'];
        $this->code[$fichero]['messages'][$mensaje]['content'][] = array(
            'line' => $linea,
            'content' => $contenido,
            'block' => $bloque,
            'readMoreLinks' => $link ? array('Documentation' => $link) : array(),
        );
        $this->types[$tipo]++;
        $this->types['code']++;
        $this->goals[$goal] = ($this->goals[$goal] ?? 0) + 1;
        $this->muestras[$goal][] = $fichero . ':' . $linea . '  ' . trim($mensaje === $g['mensaje'] ? '' : $mensaje);
    }

    public function errores(): int
    {
        return $this->types['error'];
    }

    public function avisos(): int
    {
        return $this->types['warning'];
    }

    /** JSON igual al del oficial: sin "files"/"code" cuando no hay resultados. */
    public function aOficial(): array
    {
        if ($this->fallo !== null) {
            return array('fail' => $this->fallo);
        }
        if (!$this->files && !$this->code) {
            return array();
        }
        $out = array();
        if ($this->files) {
            $out['files'] = $this->files;
            $out['readMoreLinks'] = $this->readMoreLinks;
        }
        if ($this->code) {
            $out['code'] = $this->code;
        }
        $out['types'] = $this->types;

        return $out;
    }
}
