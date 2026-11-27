<#
  Empaqueta Canal 11 para el servidor del curso (Apache + PHP, subido por FTP) y, si se indica el FTP, lo sube.

  Resultado (carpeta desplegar/salida, fuera de git):
    canal11.zip       app React compilada + index.php + .htaccess + laravel/ (con vendor sin dependencias de desarrollo,
                      sin videos, sin .env)  -> un solo archivo en vez de miles por FTP
    descomprimir.php  lo descomprime en el servidor y se borra (protegido con clave)
    instalar.php      crea el esquema, migra y siembra la BD desde el servidor y se borra (protegido con clave)
    laravel.env       configuracion del servidor: se sube UNA vez como laravel/.env (las siguientes subidas no la pisan)

  Ejemplo (solo paquete):
    .\desplegar\empaquetar.ps1 -Sitio https://www.tecnoweb.org.bo -Carpeta /inf513/grupo12sa/canal11/ -DbClave '...'
  Ejemplo (paquete + subida por FTP + base de datos, la primera vez):
    .\desplegar\empaquetar.ps1 -Sitio ... -Carpeta ... -DbClave '...' -FtpHost ... -FtpCarpeta /canal11 `
        -FtpUsuario grupo12sa -FtpClave '...' -SubirEnv -Instalar
#>
param(
    [Parameter(Mandatory)] [string] $Sitio,        # esquema y host publicos, ej. https://www.tecnoweb.org.bo
    [Parameter(Mandatory)] [string] $Carpeta,      # ruta de la app en ese sitio, ej. /inf513/grupo12sa/canal11/
    [string] $DbHost = 'localhost',                # visto desde el propio servidor (por la IP publica rechaza la conexion)
    [string] $DbPuerto = '5432',
    [string] $DbNombre = 'db_grupo12sa',
    [string] $DbUsuario = 'grupo12sa',
    [string] $DbClave,
    [string] $PhpServidor = '8.3.0',             # version de PHP del servidor: las dependencias se resuelven para ella
    [string] $Esquema = 'canal11web',              # esquema propio: no toca las tablas de otros proyectos de la misma base
    [string] $FtpHost,
    [string] $FtpCarpeta,                          # carpeta en el FTP que corresponde a $Carpeta
    [string] $FtpUsuario,
    [string] $FtpClave,
    [switch] $SubirEnv,                            # sube laravel.env como laravel/.env (primera vez o al cambiar la config)
    [switch] $Instalar                             # corre instalar.php (esquema + migraciones + siembra)
)
$ErrorActionPreference = 'Stop'
$raiz = Split-Path $PSScriptRoot -Parent
$salida = Join-Path $PSScriptRoot 'salida'
$stage = Join-Path $env:TEMP 'canal11-despliegue'          # fuera de OneDrive: vendor tiene miles de archivos
$Carpeta = '/' + $Carpeta.Trim('/') + '/'
$url = $Sitio.TrimEnd('/') + $Carpeta.TrimEnd('/')
$compose = @('compose', '-f', (Join-Path $raiz 'docker-compose.yml'))

function Paso($t) { Write-Host "`n== $t" -ForegroundColor Cyan }
# Ejecuta un programa externo: muestra su salida (tambien la de stderr, que PowerShell 5.1 trataria como error)
# y solo falla por su codigo de salida.
function Invoke-Nativo {
    $exe, $resto = $args
    $previo = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    & $exe @resto 2>&1 | ForEach-Object { Write-Host "  $_" }
    $codigo = $LASTEXITCODE; $ErrorActionPreference = $previo
    if ($codigo) { throw "$exe fallo (codigo $codigo)" }
}

Remove-Item $stage, $salida -Recurse -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory $stage, $salida | Out-Null

Paso "Compilando la app React para $Carpeta"
Invoke-Nativo docker @compose exec -T -e "WEB_BASE=$Carpeta" web sh -c "rm -rf /tmp/canal11-dist && npx vite build --outDir /tmp/canal11-dist --emptyOutDir"
Invoke-Nativo docker @compose cp web:/tmp/canal11-dist/. $stage

Paso 'Copiando Laravel (sin vendor, videos, logs ni .env)'
$back = Join-Path $raiz 'capa2-negocio'
$lar = Join-Path $stage 'laravel'
robocopy $back $lar /E /NFL /NDL /NJH /NJS /NP `
    /XD (Join-Path $back 'vendor') (Join-Path $back 'node_modules') (Join-Path $back 'tests') (Join-Path $back 'docker') `
        (Join-Path $back 'public') (Join-Path $back 'storage\app\private\videos') (Join-Path $back 'storage\logs') `
        (Join-Path $back 'storage\framework') `
    /XF .env .env.example .env.backup Dockerfile *.log .phpunit.result.cache | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy fallo ($LASTEXITCODE)" }
Get-ChildItem (Join-Path $lar 'bootstrap\cache') -Filter *.php | Remove-Item -Force   # caches con paquetes de desarrollo
foreach ($d in 'storage\app\private\videos', 'storage\logs', 'storage\framework\cache\data', 'storage\framework\sessions',
               'storage\framework\views', 'storage\framework\testing') {
    New-Item -ItemType Directory -Force (Join-Path $lar $d) | Out-Null
}
# videos de demostracion (los de los materiales sembrados); los que suben los usuarios en el servidor no se tocan
Copy-Item (Join-Path $back 'storage\app\private\videos\demo') (Join-Path $lar 'storage\app\private\videos\demo') -Recurse -Force

Paso "Instalando dependencias de produccion para PHP $PhpServidor (composer --no-dev)"
# Solo en la copia: el composer.lock del proyecto (resuelto para el PHP de Docker) no se toca
Invoke-Nativo docker run --rm -v "${lar}:/var/www" -w /var/www --entrypoint sh canal11-api:dev -c `
    "composer config platform.php $PhpServidor && composer update --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress"

Paso 'Agregando index.php y .htaccess'
$pl = Join-Path $PSScriptRoot 'plantillas'
Copy-Item (Join-Path $pl 'index.php') $stage
Copy-Item (Join-Path $pl 'raiz.htaccess') (Join-Path $stage '.htaccess')
Copy-Item (Join-Path $pl 'laravel.htaccess') (Join-Path $lar '.htaccess')

Paso 'Comprimiendo'
$zip = Join-Path $salida 'canal11.zip'
$items = Get-ChildItem $stage -Force | ForEach-Object Name
Invoke-Nativo tar.exe -a -cf $zip -C $stage @items

$clave = -join ((1..32) | ForEach-Object { '{0:x}' -f (Get-Random -Maximum 16) })
foreach ($f in 'descomprimir.php', 'instalar.php') {
    (Get-Content (Join-Path $pl $f) -Raw).Replace('__CLAVE__', $clave) | Set-Content (Join-Path $salida $f) -Encoding Ascii -NoNewline
}

Paso 'Generando laravel.env (configuracion del servidor)'
$lineas = Get-Content (Join-Path $back '.env')
$valores = [ordered]@{
    APP_ENV = 'production'; APP_DEBUG = 'false'; APP_URL = $url; LOG_LEVEL = 'error'; FRONTEND_URL = $url
    DB_HOST = $DbHost; DB_PORT = $DbPuerto; DB_DATABASE = $DbNombre; DB_USERNAME = $DbUsuario; DB_SCHEMA = $Esquema
}
if ($DbClave) { $valores.DB_PASSWORD = $DbClave }
foreach ($k in $valores.Keys) {
    $linea = "$k=$($valores[$k])"
    if ($lineas -match "^$k=") { $lineas = $lineas -replace "^$k=.*", $linea } else { $lineas += $linea }
}
$lineas | Set-Content (Join-Path $salida 'laravel.env') -Encoding UTF8
if (-not $DbClave) { Write-Warning 'Sin -DbClave: complete DB_PASSWORD en laravel.env antes de subirlo.' }

$mb = '{0:N1}' -f ((Get-Item $zip).Length / 1MB)
Write-Host "`nPaquete listo en $salida ($mb MB). URL final: $url/" -ForegroundColor Green

if (-not $FtpHost) { return }

$ftp = "ftp://$FtpHost/" + $FtpCarpeta.Trim('/') + '/'
function Subir($local, $remoto) {
    Write-Host "  subiendo $remoto"
    Invoke-Nativo curl.exe -sS --fail --ftp-create-dirs --ftp-pasv -T $local --user "${FtpUsuario}:${FtpClave}" "$ftp$remoto"
}
function Abrir($script) {
    $r = Invoke-WebRequest "$url/${script}?clave=$clave" -UseBasicParsing -TimeoutSec 900
    Write-Host $r.Content
}

Paso "Subiendo por FTP a $ftp"
Subir (Join-Path $salida 'canal11.zip') 'canal11.zip'
Subir (Join-Path $salida 'descomprimir.php') 'descomprimir.php'
Paso 'Descomprimiendo en el servidor'
Abrir 'descomprimir.php'
if ($SubirEnv) { Subir (Join-Path $salida 'laravel.env') 'laravel/.env' }
if ($Instalar) {
    Subir (Join-Path $salida 'instalar.php') 'instalar.php'
    Paso 'Preparando la base de datos'
    Abrir 'instalar.php'
}
Write-Host "`nDesplegado: $url/" -ForegroundColor Green
