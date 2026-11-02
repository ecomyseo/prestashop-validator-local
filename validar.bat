@echo off
rem Validator local de PrestaShop. Sin argumentos valida el modulo de la carpeta actual.
rem Usa el PHP de reglas\prestashop.json si existe en C:\dev-tools; si no, el "php" del PATH.
set "VLPHP=C:\dev-tools\php\php-8.2\php.exe"
if not exist "%VLPHP%" set "VLPHP=php"
"%VLPHP%" "%~dp0validar.php" %*
