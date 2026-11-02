<?php
/**
 * Servidor web local (php -S) donde se ve el resultado, como en validator.prestashop.com.
 * Se arranca oculto (sin ventana) y sigue vivo aunque termine quien lo lanzó.
 */
class VlServidor
{
    /** @var string */
    private $raiz;
    /** @var int */
    public $puerto;
    /** @var string */
    private $php;

    public function __construct(string $raiz)
    {
        $this->raiz = rtrim(str_replace('\\', '/', $raiz), '/');
        $conf = json_decode(file_get_contents($this->raiz . '/reglas/prestashop.json'), true);
        $this->puerto = (int) ($conf['puerto_web'] ?? 8077);
        $this->php = is_file($conf['php'] ?? '') ? $conf['php'] : PHP_BINARY;
    }

    public function url(string $ruta = '/'): string
    {
        return 'http://127.0.0.1:' . $this->puerto . $ruta;
    }

    public function enMarcha(): bool
    {
        $s = @fsockopen('127.0.0.1', $this->puerto, $errno, $errstr, 0.5);
        if (!$s) {
            return false;
        }
        fwrite($s, "GET /_vl_ping HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");
        stream_set_timeout($s, 3);
        $r = (string) stream_get_contents($s, 4096);
        fclose($s);

        return strpos($r, 'validator-local') !== false;
    }

    public function arrancar(): bool
    {
        if ($this->enMarcha()) {
            return true;
        }
        // Puerto ocupado por otro programa: en Windows un segundo php -S se engancha al MISMO
        // puerto sin error y las peticiones van al primero. No se arranca otro.
        if ($this->pid() !== null) {
            return false;
        }
        $args = array('-d', 'max_execution_time=0', '-d', 'memory_limit=-1', '-d', 'upload_max_filesize=30M',
            '-d', 'post_max_size=32M', '-S', '127.0.0.1:' . $this->puerto, 'web/router.php');
        // ruta relativa a WorkingDirectory: Start-Process no entrecomilla los argumentos con espacios
        if (DIRECTORY_SEPARATOR === '\\') {
            $lista = implode(',', array_map(function ($a) {
                return "'" . str_replace("'", "''", $a) . "'";
            }, $args));
            $ps = "Start-Process -WindowStyle Hidden -FilePath '" . str_replace("'", "''", $this->php) . "' -ArgumentList " . $lista
                . " -WorkingDirectory '" . str_replace("'", "''", $this->raiz) . "'";
            pclose(popen('powershell -NoProfile -ExecutionPolicy Bypass -Command "' . $ps . '"', 'r'));
        } else {
            $cmd = escapeshellarg($this->php);
            foreach ($args as $a) {
                $cmd .= ' ' . escapeshellarg($a);
            }
            exec('nohup ' . $cmd . ' > /dev/null 2>&1 &');
        }
        for ($i = 0; $i < 40; ++$i) {
            usleep(250000);
            if ($this->enMarcha()) {
                return true;
            }
        }

        return false;
    }

    /** PID que escucha en el puerto (netstat; en esta máquina no va WMI ni tasklist). */
    public function pid(): ?int
    {
        $out = (string) shell_exec('netstat -ano');
        foreach (preg_split('/\r?\n/', $out) as $l) {
            if (preg_match('#^\s*TCP\s+127\.0\.0\.1:' . $this->puerto . '\s+\S+\s+LISTENING\s+(\d+)#i', $l, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    public function parar(): bool
    {
        $pid = $this->pid();
        if (!$pid) {
            return true;
        }
        shell_exec('powershell -NoProfile -Command "Stop-Process -Id ' . $pid . ' -Force"');
        usleep(500000);

        return !$this->enMarcha();
    }

    public static function abrirNavegador(string $url): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            pclose(popen('start "" "' . $url . '"', 'r'));
        } else {
            exec('xdg-open ' . escapeshellarg($url) . ' > /dev/null 2>&1 &');
        }
    }
}
