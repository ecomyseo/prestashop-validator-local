---
name: prestashop-validator
description: Valida un módulo de PrestaShop con el VALIDATOR LOCAL, réplica de validator.prestashop.com 5.14.6 (mismas 9 pestañas, PHP CS Fixer 3.95.4 y PHPStan 1.12.33 nivel 5), y lo deja al 100 % goal a goal si se pide. Usar SIEMPRE que el usuario diga «valida este módulo», «valida el módulo», «pásale el validator», «pasa el validador», «¿pasa el validator de PrestaShop?», «déjalo al 100 % en el validator», «validate this module», «run the PrestaShop validator» o similar, en cualquier proyecto. Por defecto valida el módulo de la carpeta o proyecto actual; antes pregunta qué hacer si pasa al 100 % (instalar en el PrestaShop local, capturas, manual PDF, manual en vídeo) y, si se elige, lo hace.
---

# Validator local de PrestaShop

Réplica en local de https://validator.prestashop.com. No abre navegador ni usa la red para validar.

- Validator: `{{VALIDATOR_DIR}}` (documentación en su `README.md`)
- PHP: `{{PHP}}`

## «Valida este módulo» → qué módulo

1. Si el usuario da una ruta o un ZIP, ese.
2. Si no, **el módulo de la carpeta o proyecto actual**: el directorio de trabajo, o el primer
   directorio padre `D` que contenga `D/<nombre de D>.php`. Si el directorio de trabajo contiene
   varios módulos, el módulo es el de los ficheros abiertos o tocados en la conversación. Si sigue
   habiendo duda, se pregunta en una línea.

## Antes de validar: preguntar qué hacer si pasa al 100 %

**Siempre se pregunta antes de validar** (una sola pregunta, de selección múltiple), salvo que el usuario ya lo
haya dicho en su mensaje («valida y si pasa haz el vídeo», «solo valida»):

> Si el módulo pasa al 100 %, ¿qué hago después?
> ☐ Instalar en el PrestaShop local · ☐ Capturas de pantalla · ☐ Manual PDF con las capturas · ☐ Manual en vídeo

Las opciones salen de `"{{PHP}}" "{{VALIDATOR_DIR}}/tras100.php" --opciones`. Lo elegido va en `--tras100=`
(`instalar,capturas,manual,video`, o `no` si no quiere nada). Las capturas incluyen la instalación.
Solo se pregunta si el módulo es una carpeta dentro de `carpeta_modulos` (`reglas/prestashop.json`); con un ZIP
o fuera de esa carpeta no se puede instalar y se valida sin preguntar (`--tras100=no`).

## Cómo se ejecuta (una sola orden)

```
"{{PHP}}" "{{VALIDATOR_DIR}}/validar.php" "<carpeta_o_zip>" --muestras=3 --abrir --tras100=<lo elegido>
```

- Sin ruta, valida el módulo de la carpeta actual.
- `--abrir` deja el resultado en la **web de localhost** (http://127.0.0.1:8077/module/<id>/validate,
  con el aspecto y las pestañas del oficial) y la abre en el navegador. La web arranca sola, oculta, y
  se queda en marcha; la URL sale en la última línea («Resultado en localhost: …»). En esa página está
  el botón **«Validar en validator.prestashop.com ↗»**. Sin `--abrir`, la URL sale igual pero no se abre.
- Tarda 10-40 s: PHPStan corre, como el oficial, solo contra la versión de PrestaShop más alta del rango
  declarado (`--todas-ps` lo pasa contra todas). Lanzarlo con un tiempo límite de 10 minutos.
- Escribe los informes en `{{VALIDATOR_DIR}}/informes/<modulo>/<modulo>.{html,txt,json}`.
- Código de salida **0** = pasa al 100 % (0 errores y 0 avisos); **1** = no pasa.
- La salida marca cada goal: `[OK]` cumplido, `[XX]` error, `[!!]` aviso, `[..]` nota, con las primeras
  ocurrencias (fichero:línea).

## Si pasa al 100 % y se eligieron pasos

`validar.php` los lanza en segundo plano y lo dice («Tras el 100 %: en marcha…»). El progreso se ve en la misma
página de localhost (sección «Tras el 100 %»: cada paso, su registro, la galería de capturas, el PDF y el vídeo).
**No se contesta hasta que terminen**: se espera con

```
"{{PHP}}" "{{VALIDATOR_DIR}}/tras100.php" --esperar <id>
```

lanzado en segundo plano (puede tardar 15-20 minutos con el vídeo), y al acabar se dice el resultado. Si un paso
falla, su registro está en `{{VALIDATOR_DIR}}/trabajos/<id>/tras100-<paso>.log`: se mira la causa, se dice en una
frase y no se da por hecho. Si el módulo no pasa al 100 %, no se instala nada: se dice qué falta y se pregunta si
se arregla.

## Qué contestar

Una o dos frases: si pasa o no, cuántos errores y avisos y en qué pestañas, la URL de localhost y, si se hizo lo
de después del 100 %, dónde están el PDF y el vídeo. Sin pegar la salida entera. Si el usuario pide el detalle,
entonces se da.

## Dejarlo al 100 % (solo si el usuario lo pide)

1. Arreglos automáticos seguros:
   `"{{PHP}}" "{{VALIDATOR_DIR}}/validar.php" "<carpeta>" --arreglar`
   (`index.php` en cada carpeta, `.htaccess`, guarda `_PS_VERSION_`, cabeceras de licencia con `@author`,
   `config_xx.xml` fuera, finales de línea a LF y PHP CS Fixer con la configuración del oficial). Solo sobre
   carpetas, no sobre ZIP. **Si otra sesión está trabajando en el módulo, no se toca: se avisa.**
2. Lo que quede, a mano, **goal a goal** con la tabla de abajo.
3. Volver a validar hasta que salga `RESULTADO: 100 % OK` y código de salida 0. No se da por terminado
   antes.

**Cabecera de los PHP:** `<?php` y en la línea siguiente el bloque `/** … */`, **sin línea en blanco en
medio** (con ella, el oficial da «There must be no blank lines before the file comment»).

## Cómo se deja en verde cada goal

| Goal | Qué hacer |
|---|---|
| REQ_MAIN_FILE / REQ_MAIN_CLASS | `<modulo>/<modulo>.php` con la clase llamada como la carpeta |
| REQ_EXTENDS | la clase principal extiende `Module`, `PaymentModule` o `CarrierModule` |
| REQ_NAME | `$this->name` = nombre de la carpeta, minúsculas, dígitos y `_`, 4 caracteres o más |
| REQ_VERSION | `$this->version = 'X.Y.Z'` |
| REQ_TAB | `$this->tab` con una categoría válida (`administration`, `front_office_features`…) |
| REQ_AUTHOR / REQ_DISPLAYNAME / REQ_DESCRIPTION | definirlos en el constructor |
| REQ_COMPLIANCY | `$this->ps_versions_compliancy = ['min' => '1.7.8', 'max' => _PS_VERSION_]` (o la versión máxima real) |
| REQ_PARENT_CONSTRUCT | llamar a `parent::__construct()` |
| REQ_CONFIG_XML | borrar `config.xml` (PrestaShop lo regenera) o igualarlo a la clase |
| STR_ZIP_ONE_FOLDER / STR_ZIP_FOLDER_NAME | el ZIP lleva una sola carpeta, llamada como el módulo |
| STR_PS_VERSION_GUARD | `if (!defined('_PS_VERSION_')) { exit; }` tras la cabecera de cada PHP (no en `index.php`). En los scripts que se abren por URL y cargan ellos mismos `config.inc.php`/`init.php` (cron, ajax), **después** de esa carga: delante, el script sale en blanco siempre |
| STR_MISPLACED | CSS en `views/css`, JS en `views/js`, imágenes en `views/img`, TPL en `views/templates` |
| STR_ENGLISH | redactar `$this->description` en inglés (la traducción va en los ficheros de traducción) |
| STR_UNNEEDED | quitar `.git`, `.DS_Store`, `Thumbs.db`, `node_modules`… del paquete |
| STR_CONFIG_LANG | borrar los `config_xx.xml` de la raíz (`config_es.xml`…): los genera PrestaShop al instalar (`--arreglar` lo hace) |
| ERR_PHP_PARSE / ERR_SMARTY_PARSE | corregir la sintaxis indicada |
| ERR_INSTALL_RETURN | `install()` y `uninstall()` devuelven `true`/`false` |
| ERR_SQL_IF_EXISTS | `CREATE TABLE IF NOT EXISTS` y `DROP TABLE IF EXISTS` |
| ERR_IMAGE_TYPE | `ImageType::getFormattedName('home')` en vez de `'home_default'` |
| ERR_HOOK_DEPRECATED / ERR_HOOK_REMOVED_9 / ERR_HOOK_DISPLAYSEARCH | cambiar al hook nuevo que indica el mensaje |
| ERR_ORDERDETAIL | registrar `displayOrderDetail` en vez de usar `$HOOK_DISPLAYORDERDETAIL` |
| ERR_THEME_NAME | no escribir `classic`/`hummingbird` a mano: leer el tema del contexto |
| ERR_PS9_LIBRARY / ERR_PS9_ANNOTATION / ERR_PS9_CONFIGURATION / ERR_PS9_ADMIN_CONTROLLER | sustituir por lo que trae PrestaShop 9 (funciones nativas, atributos PHP, `PrestaShopAdminController`) |
| ERR_OLD_JQUERY | quitar el jQuery embebido y usar el de PrestaShop |
| ERR_PS_VERSION_CONDITION | comparar con `version_compare(_PS_VERSION_, 'X', '>=')` |
| ERR_CONTEXT | `$this->context` en módulos y controladores en vez de `Context::getContext()`. Cuenta cada LÍNEA con `Context::`, también comentada: el código comentado se borra |
| ERR_CONFLICT_MARKS / ERR_BOM / ERR_ENCODING / ERR_CONFIG_XML_PARSE | el oficial marca TODA línea que empiece por `<<<`, `===` o `>>>`, también separadores `=====` de un `readme.txt`: cambiarlos o quitar el fichero; quitar el BOM, guardar en UTF-8, XML válido |
| (sin goal) carpetas que no son del módulo | `.claude/`, documentación `.md`, `tmp/`, copias: no deben ir en el ZIP (el validator les pide `index.php` y cabeceras como a todo lo demás) |
| CMP_PHPSTAN | corregir cada error de PHPStan (clases, métodos o constantes que no existen en esa versión) |
| CMP_DEPRECATED | nota: cambiar el método obsoleto por el que indica el núcleo (no impide el 100 %) |
| OPT_PS_LIBRARY | usar la librería JS que ya trae PrestaShop |
| OPT_PHP_EXTENSION | `if (extension_loaded('curl'))` (o `function_exists`) antes de usar cURL o SOAP |
| OPT_EMPTY_FILE | borrar los ficheros vacíos |
| OPT_HTML_IN_PHP | el HTML (`<div`, `<p`, `<span`, `<h1`…`<h6`, `<script`) de las cadenas del PHP principal pasa a una plantilla `.tpl` (el JavaScript, a `views/js` con `Media::addJsDef` para los datos) |
| OPT_MIXED_EOL / LIC_MIXED_EOL | cada PHP con un solo tipo de final de línea: todo LF (`--arreglar` lo hace). Sale en las dos pestañas a la vez |
| TRA_QUOTES_PHP / TRA_LITERAL_PHP | `$this->l('Texto literal')` con comillas simples y sin variables |
| TRA_QUOTES_TPL / TRA_MOD_TPL | `{l s='Texto' d='Modules.Mimodulo.Admin'}` (o `mod='mimodulo'`) con comillas simples |
| LIC_FILE_DOC / LIC_FILE_DOC_SPACING | bloque `/** … */` justo en la línea siguiente a `<?php` en cada PHP (también `index.php`). Si lo siguiente al bloque es `class`, `function`, `include`/`require`… ese bloque cuenta como de ese elemento y sigue faltando el de fichero: entre los dos tiene que ir otra cosa (la guarda `_PS_VERSION_`, o en un cron una línea `// …` antes del `include` de `config.inc.php`) |
| LIC_FILE_AUTHOR | el bloque de cabecera de cada PHP lleva `@author` (se marca en la línea de su `*/`; `--arreglar` lo añade). `@copyright` y `@license` no se exigen, pero van por norma |
| LIC_HEADER | cabecera de licencia en cada JS (`/** */`) y TPL (`{** *}`) |
| LIC_YEAR / LIC_TYPE | un rango de años tiene que acabar en el año actual («2007-2021» es aviso; mejor `Since 2007`); licencia AFL, OSL, MIT, BSD, Apache, ISC o EUPL en `@license` |
| SEC_HTACCESS | `.htaccess` en la raíz del módulo (`--arreglar` lo crea) |
| SEC_INDEX_PHP | `index.php` en cada carpeta (`--arreglar` lo crea) |
| SEC_FUNCTION | sustituir la función prohibida (`Tools::file_get_contents`, `Tools::redirect`…) |
| SEC_VARIABLE | `Tools::getValue()` en vez de leer `$_GET`, `$_POST`, `$_COOKIE`, `$_SESSION` |
| SEC_SERIALIZE | `json_encode()` / `json_decode()` |
| SEC_GLOBALS | sin `global` ni `$GLOBALS`: pasar los datos como parámetros o por el contexto |
| SEC_SMARTY_ESCAPE / SEC_SMARTY_STRVAL | `{$var|escape:'html':'UTF-8'}` (o `|intval`, `|floatval`…) y sin `|strval` |
| SEC_SMARTY_NOFILTER | nota: evitar `nofilter` salvo HTML ya saneado |
| STD_PHP_CS_FIXER | `--arreglar` lo aplica con la configuración del oficial |

## Si el oficial da otra cosa

El local tiene que dar LO MISMO que validator.prestashop.com. Si el usuario enseña un informe oficial distinto:

1. Se lee el oficial (su JSON por pestaña: `https://validator.prestashop.com/module/<id>/validate/<paso>`, con
   la sesión del usuario) y se compara mensaje a mensaje, fichero a fichero y línea a línea con el local.
2. Cada diferencia es una regla mal medida: se corrige en `lib/VlComprobaciones.php` como goal
   (`lib/VlGoals.php`, origen «verificado» con el número del informe) y se le pone su caso en
   `pruebas/goals.php`, que tiene que dar `CAZADAS n de n` y `TODO EN VERDE`.
3. Se vuelve a validar el módulo y se comprueba que da los mismos números que el oficial.

## Goals

Cada verificación es un *goal* con identificador. La lista, con su origen (verificado contra un informe
oficial, herramienta oficial, `/rules` oficial o documentado), está en `{{VALIDATOR_DIR}}/lib/VlGoals.php`.
La batería que demuestra que cada goal caza su fallo: `"{{PHP}}" "{{VALIDATOR_DIR}}/pruebas/goals.php"`.
