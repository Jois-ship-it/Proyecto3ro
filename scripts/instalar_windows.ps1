<#
.SYNOPSIS
    Instalador de FlexArena y SportTime para Windows: un menu para elegir el proyecto y una instalacion completa desde cero,
    con HTTPS que el propio Windows valida.

.DESCRIPTION
    Sin parametros muestra un menu (1 = FlexArena, 2 = SportTime). Con -Proyecto se salta el menu. Sirve el archivo SOLO:
    no hace falta tener el codigo antes, porque el script lo descarga (git clone de la rama de cada proyecto).

    Hace, en este orden:
      1. Comprueba los requisitos de la PC (Windows, memoria, disco, virtualizacion e Internet).
      2. Comprueba Git y Docker Desktop; si faltan los instala (con winget o, si no hay winget, descargando el instalador
         oficial) y vuelve a comprobar; espera a que Docker este listo.
      3. Clona el repositorio (sin convertir los saltos de linea: el entrypoint del contenedor necesita LF).
      4. Crea una entidad certificadora propia (CA) y un certificado de servidor FIRMADO por ella, con la IP de
         esta maquina (y localhost) en el campo SAN. La clave de la CA queda fuera del proyecto.
      5. Instala la CA en el almacen "Entidades de certificacion raiz de confianza" de Windows: desde ese momento
         Chrome, Edge y cualquier programa de Windows validan https://<ip>/ sin advertencias.
      6. Genera el .env con contrasenas al azar (si no existe), elige puertos libres y levanta los contenedores
         (app, base de datos y phpMyAdmin) con docker compose.
      7. Comprueba que todo anda: HTTPS valido por IP, API publica, redireccion de HTTP a HTTPS e inicio de
         sesion del administrador.

    Se puede volver a ejecutar sin riesgo: actualiza el codigo, reutiliza la CA, los certificados vigentes, el .env
    y los datos. Nunca borra volumenes ni cambia contrasenas de una instalacion que ya existe.

    Para que OTRA PC Windows valide el mismo certificado, copiar ssl\ca.crt a esa PC y ejecutar alli:
        powershell -ExecutionPolicy Bypass -File instalar_windows.ps1 -SoloCertificado -CaArchivo ca.crt

    Si una descarga falla (git clone, winget, docker compose) se reintenta antes de rendirse; si falta Git o Docker Desktop
    se instalan solos. Docker Desktop recien instalado puede pedir REINICIAR la PC: se reinicia y se vuelve a ejecutar.

    Requiere Windows 10/11 con PowerShell 5.1 y permisos de administrador (se pide por UAC). Los mensajes no llevan
    tildes a proposito: asi se leen bien en cualquier consola y no dependen de la codificacion del archivo.

.PARAMETER Proyecto
    FlexArena o SportTime. Si se omite, se muestra el menu. Solo cambia el repositorio, la rama y la carpeta por omision.

.PARAMETER Repositorio
    URL del repositorio git. Por omision, el del proyecto elegido.

.PARAMETER Rama
    Rama a instalar. Por omision, la del proyecto elegido.

.PARAMETER Destino
    Carpeta donde se clona. Por omision: %USERPROFILE%\<Proyecto>.

.PARAMETER DireccionIP
    IP por la que se va a entrar al sitio (y que lleva el certificado). Por omision, la de la salida a la red de esta PC.

.PARAMETER NombresExtra
    Otros nombres o IPs para el certificado, separados por coma (por ejemplo "servidor.lan,10.0.0.5").

.PARAMETER PuertoHttps, PuertoHttp, PuertoPma
    Puertos a publicar. Por omision se usa el primero libre de: 443, 8443, 9443 / 80, 8080, 8082 / 8081, 8083, 8181.

.PARAMETER AdminEmail
    Cuenta del administrador con la que se prueba el inicio de sesion. Por omision, admin@flexarena.com en FlexArena.

.PARAMETER AdminPass, DemoPass
    Contrasenas iniciales. Si se omiten se generan al azar y quedan en el .env (la del administrador tambien se muestra
    una vez al final). Deben cumplir la politica del sistema: 8+ caracteres con mayuscula, minuscula, numero y simbolo.

.PARAMETER SinDatosDemo
    No carga los datos de demostracion (arranca solo con lo que traen schema.sql y seed.sql). Sin esto, la primera
    vez tarda varios minutos mas.

.PARAMETER AbrirFirewall
    Crea reglas de entrada en el Firewall de Windows (redes privadas y de dominio) para los puertos HTTP y HTTPS, para
    que otras PCs de la red puedan entrar. Por omision no se toca el firewall.

.PARAMETER NoInstalarCa
    Genera los certificados pero NO instala la CA en Windows (el navegador mostrara la advertencia de siempre).
    Con esta opcion y -NoInstalarHerramientas el script no necesita administrador.

.PARAMETER ConfiarEnFirefox
    Ademas, le indica a Firefox que use los certificados de Windows (politica ImportEnterpriseRoots). Firefox trae su
    propio almacen y por omision ignora el de Windows.

.PARAMETER SoloCertificado
    Solo instala una CA en esta PC (modo "cliente"): no necesita git ni Docker. Usar con -CaArchivo.

.PARAMETER CaArchivo
    Archivo ca.crt a instalar en modo -SoloCertificado.

.PARAMETER SoloUsuarioActual
    En modo -SoloCertificado, instala la CA solo para el usuario actual (no pide administrador, pero Windows muestra
    una ventana de confirmacion).

.PARAMETER NoInstalarHerramientas
    No intenta instalar Git ni Docker Desktop con winget: si faltan, el script se detiene y lo dice.

.PARAMETER NoLevantar
    Prepara todo (codigo, certificados, .env) pero no levanta los contenedores.

.PARAMETER ArchivosCompose
    Archivos compose adicionales, separados por coma (se suman a docker-compose.yml con -f).

.PARAMETER NombreComposeProyecto
    Nombre de proyecto de docker compose (-p). Por omision, el que fija docker-compose.yml.

.PARAMETER EsperaMinutos
    Cuanto esperar a que el sitio responda. La primera vez, la base tarda varios minutos en crearse. Por omision 40.

.PARAMETER NoPausar
    No espera una tecla al final cuando el script se abrio en una ventana nueva de administrador.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File instalar_windows.ps1
    Muestra el menu (FlexArena / SportTime) y hace la instalacion completa con los valores por omision.
    Es lo mismo que hacer doble clic en Instalar.bat, que tiene que estar en la misma carpeta que este archivo.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File instalar_windows.ps1 -DireccionIP 192.168.1.50 -AbrirFirewall -ConfiarEnFirefox
    Instala para que se entre por 192.168.1.50 desde otras PCs de la red, y con Firefox incluido.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File instalar_windows.ps1 -Proyecto SportTime -SinDatosDemo
    Instala SportTime sin datos de demostracion.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File instalar_windows.ps1 -SoloCertificado -CaArchivo C:\Users\yo\Desktop\ca.crt
    En una PC cliente: confiar en la CA del servidor.
#>
[CmdletBinding()]
param(
    [ValidateSet('FlexArena', 'SportTime')]
    [string]$Proyecto = 'FlexArena',    # si no se indica en la linea de comandos, se pregunta con el menu
    [string]$Repositorio,
    [string]$Rama,
    [string]$Destino,
    [string]$DireccionIP,
    [string]$NombresExtra = '',
    [int]$PuertoHttps = 0,
    [int]$PuertoHttp = 0,
    [int]$PuertoPma = 0,
    [string]$AdminEmail,
    [string]$AdminPass,
    [string]$DemoPass,
    [switch]$SinDatosDemo,
    [switch]$AbrirFirewall,
    [switch]$NoInstalarCa,
    [switch]$ConfiarEnFirefox,
    [switch]$SoloCertificado,
    [string]$CaArchivo,
    [switch]$SoloUsuarioActual,
    [switch]$NoInstalarHerramientas,
    [switch]$NoLevantar,
    [string]$ArchivosCompose = '',
    [string]$NombreComposeProyecto = '',
    [int]$EsperaMinutos = 40,
    [switch]$NoPausar,
    [switch]$SoloMenu,          # solo para pruebas: muestra el menu, imprime lo elegido y termina
    [switch]$ElevadoPorScript
)

$ErrorActionPreference = 'Stop'
$script:Parametros = $PSBoundParameters

# ---------------------------------------------------------------------------------------------------------------
# Valores por proyecto
# ---------------------------------------------------------------------------------------------------------------
$script:Presets = @{
    FlexArena = @{ Repo = 'https://github.com/Jois-ship-it/Proyecto3ro.git'; Rama = 'flexarena_final'; Carpeta = 'FlexArena'; AdminEmail = 'admin@flexarena.com' }
    SportTime = @{ Repo = 'https://github.com/ImLowU/proyecto-tercero.git';  Rama = 'mejoras-letra';   Carpeta = 'SportTime'; AdminEmail = 'admin@sporttime.com' }
}

# ---------------------------------------------------------------------------------------------------------------
# Salida por consola
# ---------------------------------------------------------------------------------------------------------------
$script:PasoActual = 0
$script:PasosTotal = 10

function Escribir-Paso([string]$texto) {
    $script:PasoActual++
    Write-Host ''
    Write-Host ("[{0}/{1}] {2}" -f $script:PasoActual, $script:PasosTotal, $texto) -ForegroundColor Cyan
}
function Escribir-Ok([string]$texto)    { Write-Host ("    OK   " + $texto) -ForegroundColor Green }
function Escribir-Info([string]$texto)  { Write-Host ("         " + $texto) }
function Escribir-Aviso([string]$texto) { Write-Host ("    AVISO " + $texto) -ForegroundColor Yellow }

function Fallar([string]$texto) { throw $texto }

# ---------------------------------------------------------------------------------------------------------------
# Utilidades generales
# ---------------------------------------------------------------------------------------------------------------
function Test-Administrador {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return ([Security.Principal.WindowsPrincipal]$id).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Actualizar-PathSesion {
    # Despues de instalar algo con winget, el PATH de esta sesion queda viejo: se vuelve a leer del sistema.
    $maquina = [Environment]::GetEnvironmentVariable('Path', 'Machine')
    $usuario = [Environment]::GetEnvironmentVariable('Path', 'User')
    $env:Path = $maquina + ';' + $usuario
}

function Invoke-Silencioso([scriptblock]$bloque) {
    # Corre un comando nativo sin ruido y devuelve su codigo de salida (sin que stderr aborte el script).
    $previo = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { & $bloque *> $null; return $LASTEXITCODE }
    catch { return 1 }
    finally { $ErrorActionPreference = $previo }
}

function Invoke-Nativo([scriptblock]$bloque) {
    # Corre un comando nativo mostrando su salida (stdout y stderr) y devuelve SOLO el codigo de salida.
    $previo = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $bloque 2>&1 | ForEach-Object {
            if ($_ -is [System.Management.Automation.ErrorRecord]) { Write-Host $_.Exception.Message } else { Write-Host $_ }
        }
        return [int]$LASTEXITCODE
    } finally { $ErrorActionPreference = $previo }
}

function Get-IntAzar([int]$maximoExclusivo) {
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $b = New-Object byte[] 4
        $rng.GetBytes($b)
        return [int]([BitConverter]::ToUInt32($b, 0) % [uint32]$maximoExclusivo)
    } finally { $rng.Dispose() }
}

function New-TextoAzar([int]$largo, [string]$alfabeto) {
    $sb = New-Object System.Text.StringBuilder
    for ($i = 0; $i -lt $largo; $i++) { [void]$sb.Append($alfabeto[(Get-IntAzar $alfabeto.Length)]) }
    return $sb.ToString()
}

function New-ClaveBase([int]$largo = 32) {
    # Solo letras y numeros: va al .env y al compose, donde un "$" o unas comillas se interpretan.
    return New-TextoAzar $largo 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'
}

function New-ClaveFuerte([int]$largo = 20) {
    # Cumple la politica del sistema (mayuscula, minuscula, numero y simbolo) con simbolos que no rompen el .env.
    $may = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; $min = 'abcdefghijkmnopqrstuvwxyz'; $dig = '23456789'; $sim = '!@%*-_+?'
    $todos = $may + $min + $dig + $sim
    $chars = @(
        (New-TextoAzar 1 $may), (New-TextoAzar 1 $min), (New-TextoAzar 1 $dig), (New-TextoAzar 1 $sim)
    ) + @((New-TextoAzar ($largo - 4) $todos).ToCharArray() | ForEach-Object { [string]$_ })
    for ($i = $chars.Count - 1; $i -gt 0; $i--) {
        $j = Get-IntAzar ($i + 1)
        $t = $chars[$i]; $chars[$i] = $chars[$j]; $chars[$j] = $t
    }
    return ($chars -join '')
}

function Test-PuertoLibre([int]$puerto) {
    if (Get-NetTCPConnection -State Listen -LocalPort $puerto -ErrorAction SilentlyContinue) { return $false }
    try {
        $l = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Any, [int]$puerto)
        $l.Start(); $l.Stop()
        return $true
    } catch { return $false }
}

function Select-Puerto([int]$pedido, [int[]]$candidatos, [int[]]$yaElegidos, [string]$nombre) {
    if ($pedido -gt 0) {
        if (-not (Test-PuertoLibre $pedido)) { Fallar "El puerto $pedido ($nombre) esta ocupado. Elija otro o libere el puerto." }
        return $pedido
    }
    foreach ($c in $candidatos) {
        if ($yaElegidos -contains $c) { continue }
        if (Test-PuertoLibre $c) { return $c }
    }
    Fallar "No encontre un puerto libre para $nombre entre: $($candidatos -join ', '). Indiquelo con el parametro correspondiente."
}

function Get-IpPrincipal {
    # La IP de la interfaz que sale a la red (sin enviar nada: solo consulta la tabla de rutas).
    try {
        $s = New-Object System.Net.Sockets.UdpClient
        try {
            $s.Connect('8.8.8.8', 53)
            $ip = $s.Client.LocalEndPoint.Address.ToString()
            if ($ip -and $ip -ne '0.0.0.0') { return $ip }
        } finally { $s.Close() }
    } catch { }
    $c = Get-IpsFisicas | Select-Object -First 1
    if ($c) { return $c }
    return '127.0.0.1'
}

function Get-IpsFisicas {
    $r = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notlike '169.254.*' -and $_.IPAddress -ne '127.0.0.1' -and $_.InterfaceAlias -notmatch 'vEthernet|WSL|Loopback|VirtualBox|VMware|Hyper-V|Docker' } |
        ForEach-Object { $_.IPAddress }
    return @($r | Where-Object { $_ })
}

function Test-EsIp([string]$texto) {
    $ip = $null
    return [System.Net.IPAddress]::TryParse($texto, [ref]$ip)
}

# ---------------------------------------------------------------------------------------------------------------
# Menu de proyectos
# ---------------------------------------------------------------------------------------------------------------
function Show-MenuProyecto {
    # Devuelve 'FlexArena', 'SportTime' o $null si la persona sale.
    $opciones = @('FlexArena', 'SportTime')
    while ($true) {
        Write-Host ''
        Write-Host '==============================================' -ForegroundColor Cyan
        Write-Host '   Instalador de proyectos para Windows' -ForegroundColor White
        Write-Host '==============================================' -ForegroundColor Cyan
        for ($i = 0; $i -lt $opciones.Count; $i++) {
            $p = $script:Presets[$opciones[$i]]
            Write-Host ('  {0}) {1,-10} rama {2}   ({3})' -f ($i + 1), $opciones[$i], $p.Rama, ($p.Repo -replace '\.git$', ''))
        }
        Write-Host '  0) Salir'
        Write-Host ''
        $r = Read-Host 'Elija una opcion (1-2, 0 para salir)'
        if ($null -eq $r) { return $null }
        $r = $r.Trim()
        if ($r -eq '0' -or $r -match '^(s|salir|q)$') { return $null }
        if ($r -match '^[12]$') { return $opciones[[int]$r - 1] }
        foreach ($o in $opciones) { if ($o -ieq $r) { return $o } }
        Write-Host 'Opcion no valida.' -ForegroundColor Yellow
    }
}

function Read-SiNo([string]$pregunta, [bool]$porOmision) {
    $sufijo = if ($porOmision) { '(S/n)' } else { '(s/N)' }
    $r = Read-Host "$pregunta $sufijo"
    if ($null -eq $r -or $r.Trim() -eq '') { return $porOmision }
    return ($r.Trim() -match '^(s|si|y|yes)$')
}

function Resolve-ProyectoConMenu {
    # Si el proyecto no vino en la linea de comandos, lo pregunta (y, de paso, la carpeta y los datos de demostracion).
    if ($script:Parametros.ContainsKey('Proyecto')) { return }
    if (-not [Environment]::UserInteractive) { Fallar 'Indique el proyecto con -Proyecto FlexArena o -Proyecto SportTime (no hay una consola donde mostrar el menu).' }
    $elegido = Show-MenuProyecto
    if (-not $elegido) { Write-Host 'No se instalo nada.'; exit 0 }
    $script:Proyecto = $elegido
    $script:Parametros['Proyecto'] = $elegido
    $preset = $script:Presets[$elegido]
    if (-not $script:Parametros.ContainsKey('Destino')) {
        $defecto = Join-Path $env:USERPROFILE $preset.Carpeta
        $d = Read-Host "Carpeta de instalacion [$defecto]"
        if ($d -and $d.Trim()) { $script:Destino = $d.Trim(); $script:Parametros['Destino'] = $d.Trim() }
    }
    if (-not $script:Parametros.ContainsKey('SinDatosDemo')) {
        if (-not (Read-SiNo 'Cargar los datos de demostracion? (la primera vez tardan varios minutos)' $true)) {
            $script:SinDatosDemo = [switch]::Present
            $script:Parametros['SinDatosDemo'] = [switch]::Present
        }
    }
    Write-Host ''
    Write-Host ("Se instalara {0} (rama {1})." -f $elegido, $preset.Rama) -ForegroundColor White
    if (-not (Read-SiNo 'Continuar?' $true)) { Write-Host 'No se instalo nada.'; exit 0 }
}

# ---------------------------------------------------------------------------------------------------------------
# Requisitos de la PC
# ---------------------------------------------------------------------------------------------------------------
function Test-Conexion([string]$servidor, [int]$puerto, [int]$milisegundos = 6000) {
    $c = New-Object System.Net.Sockets.TcpClient
    try {
        $espera = $c.BeginConnect($servidor, $puerto, $null, $null)
        if (-not $espera.AsyncWaitHandle.WaitOne($milisegundos, $false)) { return $false }
        $c.EndConnect($espera)
        return $c.Connected
    } catch { return $false } finally { $c.Close() }
}

function Test-Requisitos([string]$destino) {
    # Falla (con un mensaje claro) solo si falta algo sin lo cual la instalacion no puede terminar; el resto son avisos.
    $so = Get-CimInstance Win32_OperatingSystem
    Escribir-Ok ('Sistema: ' + $so.Caption.Trim() + ' (compilacion ' + $so.BuildNumber + ')')
    if ([int]$so.BuildNumber -lt 17763) { Fallar 'Hace falta Windows 10 (version 1809) o posterior.' }
    if ($PSVersionTable.PSVersion.Major -lt 5) { Fallar 'Hace falta PowerShell 5.1 o posterior.' }

    $ramGb = [math]::Round($so.TotalVisibleMemorySize / 1MB, 1)
    if ($ramGb -lt 6) { Escribir-Aviso "Memoria: $ramGb GB. Docker Desktop anda mejor con 8 GB o mas." } else { Escribir-Ok "Memoria: $ramGb GB" }

    $raiz = [IO.Path]::GetPathRoot([IO.Path]::GetFullPath($destino))
    $unidad = Get-PSDrive -Name $raiz.Substring(0, 1) -ErrorAction SilentlyContinue
    if ($unidad) {
        $libreGb = [math]::Round($unidad.Free / 1GB, 1)
        if ($libreGb -lt 5) { Fallar "En la unidad $raiz quedan solo $libreGb GB libres: hacen falta unos 10 GB (imagenes de Docker y base de datos)." }
        if ($libreGb -lt 15) { Escribir-Aviso "En la unidad $raiz hay $libreGb GB libres (recomendado: 15 GB o mas)." } else { Escribir-Ok "Espacio libre en ${raiz}: $libreGb GB" }
    }

    $cpu = Get-CimInstance Win32_Processor | Select-Object -First 1
    if ($cpu -and $cpu.VirtualizationFirmwareEnabled -eq $false -and -not (Get-Command docker -ErrorAction SilentlyContinue)) {
        Escribir-Aviso 'La virtualizacion esta desactivada en el BIOS/UEFI: Docker Desktop la necesita. Activela (Intel VT-x / AMD-V) antes de seguir.'
    }

    if (-not (Test-Conexion 'github.com' 443)) { Fallar 'No hay conexion con github.com (puerto 443): hace falta Internet para descargar el codigo.' }
    Escribir-Ok 'Conexion con github.com'
    if (Test-Conexion 'registry-1.docker.io' 443) { Escribir-Ok 'Conexion con Docker Hub (imagenes)' }
    else { Escribir-Aviso 'No se alcanza Docker Hub (registry-1.docker.io:443): si las imagenes no estan descargadas, docker compose va a fallar.' }

    if (Get-Command winget -ErrorAction SilentlyContinue) { Escribir-Ok 'winget disponible (instalador de programas de Windows)' }
    else { Escribir-Info 'winget no esta disponible: si falta Git o Docker se descargan sus instaladores oficiales.' }
}

# ---------------------------------------------------------------------------------------------------------------
# Herramientas: Git y Docker Desktop
# ---------------------------------------------------------------------------------------------------------------
function Resolve-Git {
    # Devuelve $true si git se puede ejecutar; si esta instalado pero fuera del PATH de esta sesion, lo agrega.
    if (Get-Command git -ErrorAction SilentlyContinue) { return $true }
    foreach ($d in @((Join-Path $env:ProgramFiles 'Git\cmd'), (Join-Path ${env:ProgramFiles(x86)} 'Git\cmd'), (Join-Path $env:LOCALAPPDATA 'Programs\Git\cmd'))) {
        if ($d -and (Test-Path (Join-Path $d 'git.exe'))) { $env:Path += ";$d"; return $true }
    }
    return $false
}

function Resolve-Docker {
    if (Get-Command docker -ErrorAction SilentlyContinue) { return $true }
    $d = Join-Path $env:ProgramFiles 'Docker\Docker\resources\bin'
    if (Test-Path (Join-Path $d 'docker.exe')) { $env:Path += ";$d"; return $true }
    return $false
}

function Save-Descarga([string]$url, [string]$archivo) {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $previo = $ProgressPreference
    $ProgressPreference = 'SilentlyContinue'     # sin la barra: en Windows PowerShell 5.1 la descarga va mucho mas rapido
    try { Invoke-WebRequest -Uri $url -OutFile $archivo -UseBasicParsing -Headers @{ 'User-Agent' = 'instalador-windows' } }
    finally { $ProgressPreference = $previo }
}

function Install-GitDirecto {
    # Sin winget: el instalador oficial de Git for Windows (ultima version publicada en GitHub), en silencio.
    Escribir-Info 'Descargando el instalador oficial de Git for Windows...'
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $rel = Invoke-RestMethod -Uri 'https://api.github.com/repos/git-for-windows/git/releases/latest' -UseBasicParsing -Headers @{ 'User-Agent' = 'instalador-windows' }
    $asset = $rel.assets | Where-Object { $_.name -match '^Git-[0-9.]+-64-bit\.exe$' } | Select-Object -First 1
    if (-not $asset) { Fallar 'No encontre el instalador de Git en la ultima version publicada.' }
    $exe = Join-Path $env:TEMP $asset.name
    Save-Descarga $asset.browser_download_url $exe
    Escribir-Info 'Instalando Git...'
    $p = Start-Process -FilePath $exe -ArgumentList @('/VERYSILENT', '/NORESTART', '/NOCANCEL', '/SP-') -Wait -PassThru
    Remove-Item $exe -Force -ErrorAction SilentlyContinue
    if ($p.ExitCode -ne 0) { Escribir-Aviso "El instalador de Git termino con el codigo $($p.ExitCode)." }
}

function Install-DockerDirecto {
    Escribir-Info 'Descargando el instalador oficial de Docker Desktop (unos 600 MB)...'
    $exe = Join-Path $env:TEMP 'DockerDesktopInstaller.exe'
    Save-Descarga 'https://desktop.docker.com/win/main/amd64/Docker%20Desktop%20Installer.exe' $exe
    Escribir-Info 'Instalando Docker Desktop (varios minutos)...'
    $p = Start-Process -FilePath $exe -ArgumentList @('install', '--quiet', '--accept-license') -Wait -PassThru
    Remove-Item $exe -Force -ErrorAction SilentlyContinue
    if ($p.ExitCode -ne 0) { Escribir-Aviso "El instalador de Docker Desktop termino con el codigo $($p.ExitCode)." }
}

function Install-Herramienta([string]$wingetId, [string]$nombre, [scriptblock]$disponible, [scriptblock]$directo) {
    # Instala con winget si esta; si winget falta o no deja la herramienta disponible, usa la descarga directa. Reintenta y vuelve a comprobar.
    if ($NoInstalarHerramientas) { Fallar "Falta $nombre y se indico -NoInstalarHerramientas. Instalelo y vuelva a ejecutar." }
    for ($intento = 1; $intento -le 2; $intento++) {
        if (Get-Command winget -ErrorAction SilentlyContinue) {
            Escribir-Info "Instalando $nombre con winget (intento $intento de 2; puede tardar varios minutos)..."
            $codigo = Invoke-Nativo { winget install --exact --id $wingetId --silent --accept-package-agreements --accept-source-agreements }
            Actualizar-PathSesion
            if ($codigo -ne 0 -and $codigo -ne -1978335189) { Escribir-Aviso "winget devolvio el codigo $codigo al instalar $nombre." }
            if (& $disponible) { return $true }
        } else {
            Escribir-Info "Esta PC no tiene winget."
        }
        Escribir-Info "Probando la descarga directa de $nombre..."
        try { & $directo } catch { Escribir-Aviso ('La descarga directa fallo: ' + $_.Exception.Message) }
        Actualizar-PathSesion
        if (& $disponible) { return $true }
        if ($intento -lt 2) { Escribir-Aviso "$nombre todavia no esta disponible; reintento en 10 segundos..."; Start-Sleep -Seconds 10 }
    }
    return $false
}

function Test-DockerListo {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { return $false }
    return ((Invoke-Silencioso { docker info }) -eq 0)
}

function Start-DockerDesktop {
    $exe = Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'
    if (-not (Test-Path $exe)) { return $false }
    if (-not (Get-Process -Name 'Docker Desktop' -ErrorAction SilentlyContinue)) {
        Escribir-Info 'Iniciando Docker Desktop...'
        Start-Process -FilePath $exe | Out-Null
    }
    return $true
}

function Wait-Docker([int]$minutos) {
    $limite = (Get-Date).AddMinutes($minutos)
    $avisado = $false
    while ((Get-Date) -lt $limite) {
        if (Test-DockerListo) { return $true }
        if (-not $avisado -and ((Get-Date) -gt $limite.AddMinutes(-$minutos + 2))) {
            Escribir-Aviso 'Docker Desktop todavia no responde. Si muestra una ventana con los terminos de servicio, acepte; si pide activar WSL 2 o la virtualizacion, hagalo.'
            $avisado = $true
        }
        Start-Sleep -Seconds 5
    }
    return $false
}

function Assert-Herramientas {
    # Git
    if (-not (Resolve-Git)) {
        Escribir-Aviso 'Git no esta instalado: se instala ahora.'
        [void](Install-Herramienta 'Git.Git' 'Git' { Resolve-Git } { Install-GitDirecto })
        if (-not (Resolve-Git)) { Fallar 'No pude instalar Git. Instalelo desde https://git-scm.com/download/win y vuelva a ejecutar.' }
    }
    Escribir-Ok ((& git --version) + '')

    # Docker
    $recienInstalado = $false
    if (-not (Resolve-Docker)) {
        Escribir-Aviso 'Docker Desktop no esta instalado: se instala ahora.'
        $recienInstalado = $true
        [void](Install-Herramienta 'Docker.DockerDesktop' 'Docker Desktop' { Resolve-Docker } { Install-DockerDirecto })
        if (-not (Resolve-Docker)) {
            Fallar 'Docker Desktop se instalo pero hace falta REINICIAR la PC. Reinicie y vuelva a ejecutar este script.'
        }
    }
    if (-not (Test-DockerListo)) {
        [void](Start-DockerDesktop)
        Escribir-Info 'Esperando a que Docker este listo (hasta 6 minutos)...'
        if (-not (Wait-Docker 6)) {
            if ($recienInstalado) { Fallar 'Docker Desktop recien instalado necesita REINICIAR la PC (y quiza activar WSL 2). Reinicie y vuelva a ejecutar este script.' }
            Fallar 'Docker no responde. Abra Docker Desktop, espere a que diga "Engine running" y vuelva a ejecutar el script.'
        }
    }
    Escribir-Ok ((& docker --version) + '')
    if ((Invoke-Silencioso { docker compose version }) -ne 0) {
        Fallar 'Falta "docker compose" (v2). Actualice Docker Desktop.'
    }
    Escribir-Ok ((& docker compose version) + '')
}

# ---------------------------------------------------------------------------------------------------------------
# Codigo fuente
# ---------------------------------------------------------------------------------------------------------------
function Sync-Repositorio([string]$repo, [string]$rama, [string]$carpeta) {
    $previo = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        if (Test-Path (Join-Path $carpeta '.git')) {
            Escribir-Info "Ya hay un repositorio en ${carpeta}: se actualiza."
            & git -C $carpeta config core.autocrlf false
            & git -C $carpeta fetch origin $rama
            if ($LASTEXITCODE -ne 0) { Fallar 'git fetch fallo (red o credenciales).' }
            & git -C $carpeta checkout $rama
            if ($LASTEXITCODE -ne 0) { Fallar "No pude pasar a la rama $rama (hay cambios locales sin guardar?)." }
            & git -C $carpeta pull --ff-only origin $rama
            if ($LASTEXITCODE -ne 0) { Fallar 'git pull --ff-only fallo: la copia local tiene cambios o historia distinta. Resuelvalo a mano o use otra carpeta -Destino.' }
        } else {
            if ((Test-Path $carpeta) -and (Get-ChildItem -LiteralPath $carpeta -Force | Select-Object -First 1)) {
                Fallar "La carpeta $carpeta existe y no esta vacia ni es un repositorio. Use otra con -Destino."
            }
            $padre = Split-Path -Parent $carpeta
            if ($padre -and -not (Test-Path $padre)) { New-Item -ItemType Directory -Path $padre -Force | Out-Null }
            Escribir-Info "Clonando $repo (rama $rama)..."
            for ($intento = 1; $intento -le 3; $intento++) {
                & git -c core.autocrlf=false clone --branch $rama --single-branch $repo $carpeta
                if ($LASTEXITCODE -eq 0) { break }
                if (Test-Path $carpeta) { Remove-Item -LiteralPath $carpeta -Recurse -Force -ErrorAction SilentlyContinue }   # clon a medias (la carpeta estaba vacia o no existia)
                if ($intento -eq 3) {
                    Fallar 'git clone fallo 3 veces. Revise la conexion; si el repositorio es privado, inicie sesion de GitHub cuando git lo pida (o configure un token) y vuelva a ejecutar.'
                }
                Escribir-Aviso "git clone fallo (intento $intento de 3); reintento en 10 segundos..."
                Start-Sleep -Seconds 10
            }
            & git -C $carpeta config core.autocrlf false
        }
    } finally { $ErrorActionPreference = $previo }
    Escribir-Ok ('Codigo en ' + $carpeta + ' (' + ((& git -C $carpeta rev-parse --short HEAD) + '') + ')')
}

function Repair-FinesDeLinea([string]$carpeta) {
    # Un script de shell con CRLF no arranca dentro del contenedor ("bash\r: no such file"). Git no los convierte
    # (clonamos con autocrlf=false), pero si el repositorio ya estaba clonado con otra configuracion se arreglan aca.
    $arreglados = 0
    $utf8 = New-Object System.Text.UTF8Encoding $false
    foreach ($sub in 'docker', 'scripts') {
        $dir = Join-Path $carpeta $sub
        if (-not (Test-Path $dir)) { continue }
        foreach ($f in Get-ChildItem -Path $dir -Filter '*.sh' -File) {
            $t = [IO.File]::ReadAllText($f.FullName)
            if ($t.Contains("`r")) {
                [IO.File]::WriteAllText($f.FullName, $t.Replace("`r`n", "`n"), $utf8)
                $arreglados++
            }
        }
    }
    if ($arreglados -gt 0) { Escribir-Aviso "Convertidos a LF $arreglados script(s) de shell que tenian CRLF." }
    else { Escribir-Ok 'Los scripts de shell tienen saltos de linea LF.' }
}

# ---------------------------------------------------------------------------------------------------------------
# Certificados
# ---------------------------------------------------------------------------------------------------------------
function Get-OpenSsl {
    $git = Get-Command git -ErrorAction SilentlyContinue
    $cands = @()
    if ($git) {
        $raiz = Split-Path -Parent (Split-Path -Parent $git.Source)
        $cands += (Join-Path $raiz 'mingw64\bin\openssl.exe')
        $cands += (Join-Path $raiz 'usr\bin\openssl.exe')
    }
    $cands += (Join-Path $env:ProgramFiles 'Git\mingw64\bin\openssl.exe')
    $cands += (Join-Path $env:ProgramFiles 'OpenSSL-Win64\bin\openssl.exe')
    foreach ($c in $cands) { if ($c -and (Test-Path $c)) { return $c } }
    $o = Get-Command openssl -ErrorAction SilentlyContinue
    if ($o) { return $o.Source }
    return $null
}

function Invoke-OpenSsl([string]$exe, [string[]]$argumentos) {
    $previo = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $salida = & $exe @argumentos 2>&1 | ForEach-Object { "$_" }
        $codigo = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previo }
    if ($codigo -ne 0) { Fallar ("openssl " + ($argumentos -join ' ') + " fallo (codigo $codigo):`n" + ($salida -join "`n")) }
    return $salida
}

function Protect-Carpeta([string]$carpeta) {
    # Solo administradores y SYSTEM (la clave de la CA firma certificados: no debe leerla cualquiera).
    if (-not (Test-Administrador)) { return }
    [void](Invoke-Silencioso { icacls $carpeta /inheritance:r /grant:r '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-18:(OI)(CI)F' })
}

function Test-CertificadoServidorVigente([string]$openssl, [string]$certificado, [string]$ca, [string[]]$sanEsperados) {
    # Reutiliza el certificado actual si: lo firmo esta CA, no vence en 30 dias y cubre TODOS los nombres pedidos.
    if (-not (Test-Path $certificado) -or -not (Test-Path $ca)) { return $false }
    try {
        [void](Invoke-OpenSsl $openssl @('verify', '-CAfile', $ca, $certificado))
        $vence = Invoke-Silencioso { & $openssl x509 -in $certificado -noout -checkend 2592000 }
        if ($vence -ne 0) { return $false }
        $san = (Invoke-OpenSsl $openssl @('x509', '-in', $certificado, '-noout', '-ext', 'subjectAltName')) -join ' '
        foreach ($e in $sanEsperados) {
            if ($e -eq '::1') { continue }   # openssl lo escribe como 0:0:0:0:0:0:0:1
            $tipo = if (Test-EsIp $e) { 'IP Address:' } else { 'DNS:' }
            if ($san -notmatch [regex]::Escape($tipo + $e) + '(,|\s|$)') { return $false }
        }
        return $true
    } catch { return $false }
}

$script:CertificadoNuevo = $false

function New-Certificados([string]$carpetaProyecto, [string]$carpetaCa, [string]$nombreProyecto, [string[]]$nombres) {
    $openssl = Get-OpenSsl
    if (-not $openssl) { Fallar 'No encontre openssl (viene con Git para Windows). Reinstale Git y vuelva a ejecutar.' }
    Escribir-Info ('openssl: ' + $openssl)

    $ssl = Join-Path $carpetaProyecto 'ssl'
    if (-not (Test-Path $ssl)) { New-Item -ItemType Directory -Path $ssl -Force | Out-Null }
    if (-not (Test-Path $carpetaCa)) { New-Item -ItemType Directory -Path $carpetaCa -Force | Out-Null }
    Protect-Carpeta $carpetaCa

    $caKey = Join-Path $carpetaCa 'ca.key'
    $caCrt = Join-Path $carpetaCa 'ca.crt'
    $cfg   = Join-Path $carpetaCa 'openssl-temporal.cnf'
    $srvCsr = Join-Path $carpetaCa 'servidor.csr'
    $crt   = Join-Path $ssl 'certificate.crt'
    $key   = Join-Path $ssl 'private.key'

    # Config de OpenSSL propia: no depende de la que traiga (o no) cada instalacion.
    $lineas = New-Object System.Collections.Generic.List[string]
    $lineas.Add('[req]'); $lineas.Add('distinguished_name = dn'); $lineas.Add('prompt = no'); $lineas.Add('x509_extensions = v3_ca')
    $lineas.Add('[dn]'); $lineas.Add("CN = $nombreProyecto Local CA ($env:COMPUTERNAME)"); $lineas.Add("O = $nombreProyecto")
    $lineas.Add('[v3_ca]'); $lineas.Add('basicConstraints = critical,CA:TRUE,pathlen:0'); $lineas.Add('keyUsage = critical,keyCertSign,cRLSign'); $lineas.Add('subjectKeyIdentifier = hash')
    $lineas.Add('[v3_srv]'); $lineas.Add('basicConstraints = CA:FALSE'); $lineas.Add('keyUsage = critical,digitalSignature,keyEncipherment')
    $lineas.Add('extendedKeyUsage = serverAuth'); $lineas.Add('subjectKeyIdentifier = hash'); $lineas.Add('authorityKeyIdentifier = keyid,issuer'); $lineas.Add('subjectAltName = @alt')
    $lineas.Add('[alt]')
    $i = 0; $d = 0
    foreach ($n in $nombres) {
        if (Test-EsIp $n) { $i++; $lineas.Add("IP.$i = $n") } else { $d++; $lineas.Add("DNS.$d = $n") }
    }
    $ascii = New-Object System.Text.UTF8Encoding $false
    [IO.File]::WriteAllText($cfg, (($lineas -join "`n") + "`n"), $ascii)

    $creoCa = $false
    Push-Location $carpetaCa
    try {
        if (-not ((Test-Path $caKey) -and (Test-Path $caCrt))) {
            Escribir-Info 'Creando la entidad certificadora (CA) propia...'
            [void](Invoke-OpenSsl $openssl @('genrsa', '-out', $caKey, '4096'))
            [void](Invoke-OpenSsl $openssl @('req', '-x509', '-new', '-nodes', '-key', $caKey, '-sha256', '-days', '3650', '-config', $cfg, '-out', $caCrt))
            $creoCa = $true
        } else {
            Escribir-Ok 'Se reutiliza la CA que ya existe (los clientes que ya confian en ella no necesitan hacer nada).'
        }

        $sanEsperados = $nombres
        if (-not $creoCa -and (Test-CertificadoServidorVigente $openssl $crt $caCrt $sanEsperados) -and (Test-Path $key)) {
            Escribir-Ok 'El certificado del servidor sigue vigente y cubre todas las direcciones: se reutiliza.'
        } else {
            Escribir-Info ('Firmando el certificado del servidor para: ' + ($nombres -join ', '))
            $cn = $nombres[0]
            [void](Invoke-OpenSsl $openssl @('genrsa', '-out', $key, '2048'))
            [void](Invoke-OpenSsl $openssl @('req', '-new', '-key', $key, '-subj', "/CN=$cn/O=$nombreProyecto", '-config', $cfg, '-out', $srvCsr))
            $script:CertificadoNuevo = $true
            [void](Invoke-OpenSsl $openssl @('x509', '-req', '-in', $srvCsr, '-CA', $caCrt, '-CAkey', $caKey, '-CAcreateserial', '-sha256', '-days', '825', '-extfile', $cfg, '-extensions', 'v3_srv', '-out', $crt))
            Remove-Item -LiteralPath $srvCsr -Force -ErrorAction SilentlyContinue
        }
        # La prueba que importa: el certificado se verifica contra la CA y el nombre contra la IP.
        $ipPrincipal = $nombres[0]
        $flag = if (Test-EsIp $ipPrincipal) { '-verify_ip' } else { '-verify_hostname' }
        [void](Invoke-OpenSsl $openssl @('verify', '-CAfile', $caCrt, $flag, $ipPrincipal, '-x509_strict', $crt))
        Escribir-Ok ('Certificado firmado por la CA y valido para ' + $ipPrincipal)
    } finally {
        Pop-Location
        Remove-Item -LiteralPath $cfg -Force -ErrorAction SilentlyContinue
    }

    # La parte publica de la CA se copia al proyecto para poder repartirla a otras PCs; la clave NO sale de $carpetaCa.
    Copy-Item -LiteralPath $caCrt -Destination (Join-Path $ssl 'ca.crt') -Force
    return $caCrt
}

function Get-HuellaCa([string]$archivo) {
    $c = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2 $archivo
    return $c.Thumbprint
}

function Test-CaInstalada([string]$archivo, [string]$almacen) {
    $h = Get-HuellaCa $archivo
    return [bool](Get-ChildItem -Path $almacen -ErrorAction SilentlyContinue | Where-Object { $_.Thumbprint -eq $h })
}

function Install-CaEnWindows([string]$archivo, [bool]$soloUsuario) {
    $almacen = if ($soloUsuario) { 'Cert:\CurrentUser\Root' } else { 'Cert:\LocalMachine\Root' }
    if (Test-CaInstalada $archivo $almacen) {
        Escribir-Ok 'Windows ya confiaba en esta CA.'
        return $true
    }
    if ($soloUsuario) { Escribir-Aviso 'Windows va a mostrar una ventana de confirmacion: elija "Si".' }
    [void](Import-Certificate -FilePath $archivo -CertStoreLocation $almacen)
    if (Test-CaInstalada $archivo $almacen) {
        Escribir-Ok ("CA instalada en $almacen (huella " + (Get-HuellaCa $archivo) + ').')
        return $true
    }
    Escribir-Aviso 'No pude confirmar que la CA quedo instalada.'
    return $false
}

function Set-ConfianzaFirefox {
    $ruta = 'HKLM:\SOFTWARE\Policies\Mozilla\Firefox\Certificates'
    if (-not (Test-Path $ruta)) { New-Item -Path $ruta -Force | Out-Null }
    Set-ItemProperty -Path $ruta -Name 'ImportEnterpriseRoots' -Value 1 -Type DWord
    Escribir-Ok 'Firefox usara los certificados de Windows (reinicie Firefox si estaba abierto).'
}

# ---------------------------------------------------------------------------------------------------------------
# .env
# ---------------------------------------------------------------------------------------------------------------
function Set-ValorEnv([System.Collections.Generic.List[string]]$lineas, [string]$clave, [string]$valor) {
    for ($i = 0; $i -lt $lineas.Count; $i++) {
        if ($lineas[$i] -match ('^\s*' + [regex]::Escape($clave) + '=')) { $lineas[$i] = "$clave=$valor"; return }
    }
    $lineas.Add("$clave=$valor")
}

function Get-ValorEnv([string]$archivo, [string]$clave) {
    if (-not (Test-Path $archivo)) { return $null }
    foreach ($l in [IO.File]::ReadAllLines($archivo)) {
        if ($l -match ('^\s*' + [regex]::Escape($clave) + '=(.*)$')) { return $Matches[1].Trim().Trim('"').Trim("'") }
    }
    return $null
}

function Write-Env([string]$archivo, [System.Collections.Generic.List[string]]$lineas) {
    $utf8 = New-Object System.Text.UTF8Encoding $false
    [IO.File]::WriteAllText($archivo, (($lineas -join "`n") + "`n"), $utf8)
}

# ---------------------------------------------------------------------------------------------------------------
# Comprobaciones por HTTP(S)
# ---------------------------------------------------------------------------------------------------------------
function Initialize-Tls {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    if (-not ('FlexTrust' -as [type])) {
        Add-Type -TypeDefinition @'
using System;
using System.Net;
using System.Net.Security;
using System.Security.Cryptography.X509Certificates;

public static class FlexTrust {
    public static string CaPath;

    // Acepta un certificado que Windows no reconoce SOLO si lo firma exactamente la CA indicada y el nombre coincide.
    public static bool Validar(object sender, X509Certificate cert, X509Chain chain, SslPolicyErrors errors) {
        if (errors == SslPolicyErrors.None) return true;
        if (CaPath == null) return false;
        if ((errors & SslPolicyErrors.RemoteCertificateNameMismatch) != 0) return false;
        if ((errors & SslPolicyErrors.RemoteCertificateNotAvailable) != 0) return false;
        X509Certificate2 ca = new X509Certificate2(CaPath);
        X509Certificate2 c2 = new X509Certificate2(cert);
        X509Chain ch = new X509Chain();
        ch.ChainPolicy.RevocationMode = X509RevocationMode.NoCheck;
        ch.ChainPolicy.ExtraStore.Add(ca);
        ch.ChainPolicy.VerificationFlags = X509VerificationFlags.AllowUnknownCertificateAuthority;
        ch.Build(c2);
        foreach (X509ChainStatus s in ch.ChainStatus) {
            if (s.Status != X509ChainStatusFlags.NoError && s.Status != X509ChainStatusFlags.UntrustedRoot) return false;
        }
        X509Certificate2 raiz = ch.ChainElements[ch.ChainElements.Count - 1].Certificate;
        return raiz.Thumbprint == ca.Thumbprint;
    }

    public static void Activar(string caPath) {
        CaPath = caPath;
        ServicePointManager.ServerCertificateValidationCallback = new RemoteCertificateValidationCallback(Validar);
    }

    public static void Desactivar() {
        CaPath = null;
        ServicePointManager.ServerCertificateValidationCallback = null;
    }
}
'@
    }
}

function ConvertTo-Formulario([hashtable]$datos) {
    return (($datos.GetEnumerator() | ForEach-Object { [Uri]::EscapeDataString([string]$_.Key) + '=' + [Uri]::EscapeDataString([string]$_.Value) }) -join '&')
}

function Invoke-Web([string]$url, $cookies = $null, [string]$metodo = 'GET', [string]$cuerpo = '', [bool]$seguir = $true) {
    # Devuelve @{ Estado; Contenido; Ubicacion; Error } sin lanzar por los codigos 3xx/4xx/5xx.
    $r = @{ Estado = 0; Contenido = ''; Ubicacion = ''; Error = '' }
    $resp = $null
    try {
        $req = [System.Net.HttpWebRequest][System.Net.WebRequest]::Create($url)
        $req.Method = $metodo
        $req.Timeout = 30000
        $req.AllowAutoRedirect = $seguir
        $req.Proxy = $null
        $req.UserAgent = 'instalar_windows.ps1'
        if ($cookies) { $req.CookieContainer = $cookies }
        if ($cuerpo) {
            $bytes = [Text.Encoding]::UTF8.GetBytes($cuerpo)
            $req.ContentType = 'application/x-www-form-urlencoded'
            $req.ContentLength = $bytes.Length
            $st = $req.GetRequestStream(); $st.Write($bytes, 0, $bytes.Length); $st.Close()
        }
        $resp = $req.GetResponse()
    } catch [System.Net.WebException] {
        $resp = $_.Exception.Response
        if (-not $resp) { $r.Error = $_.Exception.Message; return $r }
    } catch {
        $r.Error = $_.Exception.Message
        return $r
    }
    $r.Estado = [int]$resp.StatusCode
    $r.Ubicacion = [string]$resp.Headers['Location']
    try { $sr = New-Object IO.StreamReader($resp.GetResponseStream()); $r.Contenido = $sr.ReadToEnd(); $sr.Close() } catch { }
    $resp.Close()
    return $r
}

function Wait-Sitio([string]$url, [int]$minutos, [scriptblock]$alFallar) {
    $limite = (Get-Date).AddMinutes($minutos)
    $ultimo = Get-Date
    while ((Get-Date) -lt $limite) {
        $r = Invoke-Web $url
        if ($r.Estado -ge 200 -and $r.Estado -lt 400) { return $r }
        if ($alFallar) { & $alFallar }
        if (((Get-Date) - $ultimo).TotalSeconds -ge 30) {
            Escribir-Info ('...esperando (' + [int](($limite - (Get-Date)).TotalMinutes) + ' min de margen). Ultimo resultado: ' + $(if ($r.Estado) { "HTTP $($r.Estado)" } else { $r.Error }))
            $ultimo = Get-Date
        }
        Start-Sleep -Seconds 5
    }
    return $null
}

# ---------------------------------------------------------------------------------------------------------------
# docker compose
# ---------------------------------------------------------------------------------------------------------------
function Get-EstadoCompose([string[]]$base) {
    $previo = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { $salida = & docker @base ps -a --format json 2>$null } finally { $ErrorActionPreference = $previo }
    $txt = (($salida | ForEach-Object { "$_" }) -join "`n").Trim()
    if (-not $txt) { return @() }
    try {
        if ($txt.StartsWith('[')) { return @($txt | ConvertFrom-Json) }
        return @($txt -split "`n" | Where-Object { $_.Trim() } | ForEach-Object { $_ | ConvertFrom-Json })
    } catch { return @() }
}

# ---------------------------------------------------------------------------------------------------------------
# Programa principal
# ---------------------------------------------------------------------------------------------------------------
function Start-Instalacion {
    if (-not $SoloCertificado) { Resolve-ProyectoConMenu }
    if ($SoloMenu) { Write-Host ("MENU_RESULTADO Proyecto=$Proyecto Destino=$Destino SinDatosDemo=$([bool]$SinDatosDemo)"); return }
    $preset = $script:Presets[$Proyecto]
    if (-not $Repositorio) { $Repositorio = $preset.Repo }
    if (-not $Rama)        { $Rama = $preset.Rama }
    if (-not $Destino)     { $Destino = Join-Path $env:USERPROFILE $preset.Carpeta }
    if (-not $AdminEmail)  { $AdminEmail = $preset.AdminEmail }
    $Destino = [IO.Path]::GetFullPath($Destino)

    # Que pasos necesitan administrador
    $necesitaAdmin = $true
    if ($SoloCertificado) { $necesitaAdmin = -not $SoloUsuarioActual }
    elseif ($NoInstalarCa -and $NoInstalarHerramientas -and -not $AbrirFirewall -and -not $ConfiarEnFirefox) { $necesitaAdmin = $false }

    if ($necesitaAdmin -and -not (Test-Administrador)) {
        if (-not $PSCommandPath) { Fallar 'Ejecute este archivo con: powershell -ExecutionPolicy Bypass -File instalar_windows.ps1 (no pegado en la consola).' }
        Write-Host 'Se necesitan permisos de administrador (para instalar la CA en Windows). Se abre una ventana nueva; acepte el aviso de Windows.' -ForegroundColor Yellow
        # La ventana de administrador puede correr con otro usuario (otro %USERPROFILE%): se le pasa la carpeta ya resuelta.
        $script:Parametros['Destino'] = $Destino
        $a = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"' + $PSCommandPath + '"'))
        foreach ($k in $script:Parametros.Keys) {
            $v = $script:Parametros[$k]
            if ($v -is [System.Management.Automation.SwitchParameter]) { if ($v.IsPresent) { $a += "-$k" } }
            else { $a += "-$k"; $a += ('"' + (([string]$v) -replace '"', '\"') + '"') }
        }
        $a += '-ElevadoPorScript'
        Start-Process -FilePath 'powershell.exe' -ArgumentList $a -Verb RunAs -Wait
        return
    }

    Initialize-Tls

    # ------------------------------------------------------------------ modo cliente: solo confiar en una CA
    if ($SoloCertificado) {
        $script:PasosTotal = 1
        Escribir-Paso 'Instalando la CA en esta PC'
        if (-not $CaArchivo) {
            $porDefecto = Join-Path (Split-Path -Parent $PSCommandPath) '..\ssl\ca.crt'
            if (Test-Path $porDefecto) { $CaArchivo = (Resolve-Path $porDefecto).Path }
        }
        if (-not $CaArchivo -or -not (Test-Path $CaArchivo)) { Fallar 'Indique el archivo de la CA con -CaArchivo (es el ssl\ca.crt del servidor).' }
        $CaArchivo = (Resolve-Path $CaArchivo).Path
        [void](Install-CaEnWindows $CaArchivo ([bool]$SoloUsuarioActual))
        if ($ConfiarEnFirefox -and (Test-Administrador)) { Set-ConfianzaFirefox }
        Write-Host ''
        Write-Host 'Listo: esta PC ya valida los certificados firmados por esa CA.' -ForegroundColor Green
        return
    }

    Write-Host ''
    Write-Host "=== Instalacion de $Proyecto en Windows ===" -ForegroundColor White
    Write-Host "Repositorio: $Repositorio ($Rama)"
    Write-Host "Carpeta:     $Destino"

    # ------------------------------------------------------------------ 1. requisitos de la PC
    Escribir-Paso 'Comprobando los requisitos de la PC'
    Test-Requisitos $Destino

    # ------------------------------------------------------------------ 2. herramientas
    Escribir-Paso 'Comprobando Git y Docker (si faltan, se instalan)'
    Assert-Herramientas

    # ------------------------------------------------------------------ 3. codigo
    Escribir-Paso 'Descargando el codigo'
    Sync-Repositorio $Repositorio $Rama $Destino
    Repair-FinesDeLinea $Destino
    foreach ($f in 'docker-compose.yml', 'Dockerfile', '.env.example') {
        if (-not (Test-Path (Join-Path $Destino $f))) { Fallar "El repositorio no trae ${f}: no parece el proyecto correcto." }
    }

    # ------------------------------------------------------------------ 4. nombres del certificado
    Escribir-Paso 'Eligiendo la direccion del sitio'
    $ip = if ($DireccionIP) { $DireccionIP } else { Get-IpPrincipal }
    if (-not (Test-EsIp $ip)) { Fallar "La direccion '$ip' no es una IP valida." }
    $nombres = New-Object System.Collections.Generic.List[string]
    $nombres.Add($ip)
    foreach ($x in (@(Get-IpsFisicas) + @('127.0.0.1', '::1', 'localhost', $env:COMPUTERNAME.ToLower()))) { if ($x -and -not $nombres.Contains($x)) { $nombres.Add($x) } }
    foreach ($x in ($NombresExtra -split ',')) { $x = $x.Trim(); if ($x -and -not $nombres.Contains($x)) { $nombres.Add($x) } }
    Escribir-Ok "Se entra por la IP $ip"
    Escribir-Info ('El certificado cubrira: ' + ($nombres -join ', '))

    # ------------------------------------------------------------------ 5. certificados
    Escribir-Paso 'Creando los certificados (CA propia + certificado del servidor firmado)'
    $baseCa = if (Test-Administrador) { $env:ProgramData } else { $env:LOCALAPPDATA }
    $carpetaCa = Join-Path (Join-Path $baseCa $preset.Carpeta) 'CA'
    $caCrt = New-Certificados $Destino $carpetaCa $preset.Carpeta ($nombres.ToArray())
    Escribir-Info ('Clave de la CA (privada): ' + $carpetaCa)
    Escribir-Info ('Certificado publico de la CA para repartir: ' + (Join-Path $Destino 'ssl\ca.crt'))

    # ------------------------------------------------------------------ 6. confianza en Windows
    Escribir-Paso 'Instalando la CA en Windows'
    $confiable = $false
    if ($NoInstalarCa) {
        Escribir-Aviso 'Se indico -NoInstalarCa: el navegador mostrara la advertencia de certificado hasta que se instale ssl\ca.crt.'
    } else {
        $confiable = Install-CaEnWindows $caCrt $false
        if ($ConfiarEnFirefox) { Set-ConfianzaFirefox }
    }

    # ------------------------------------------------------------------ 7. .env
    Escribir-Paso 'Preparando la configuracion (.env)'
    $envArchivo = Join-Path $Destino '.env'
    $instalacionNueva = -not (Test-Path $envArchivo)
    if ($instalacionNueva) {
        $https = Select-Puerto $PuertoHttps @(443, 8443, 9443, 10443, 18443) @() 'HTTPS'
        $http  = Select-Puerto $PuertoHttp  @(80, 8080, 8082, 8090, 18080) @($https) 'HTTP'
        $pma   = Select-Puerto $PuertoPma   @(8081, 8083, 8181, 18081) @($https, $http) 'phpMyAdmin'
        $lineas = New-Object System.Collections.Generic.List[string]
        foreach ($l in [IO.File]::ReadAllLines((Join-Path $Destino '.env.example'))) { $lineas.Add($l) }
        $url = if ($https -eq 443) { "https://$ip" } else { "https://${ip}:$https" }
        $adminClave = if ($AdminPass) { $AdminPass } else { New-ClaveFuerte 20 }
        $demoClave = ''
        if (-not $SinDatosDemo) { $demoClave = if ($DemoPass) { $DemoPass } else { New-ClaveFuerte 20 } }
        Set-ValorEnv $lineas 'APP_ENV' 'production'
        Set-ValorEnv $lineas 'APP_URL' $url
        Set-ValorEnv $lineas 'HTTP_PORT' "$http"
        Set-ValorEnv $lineas 'HTTPS_PORT' "$https"
        Set-ValorEnv $lineas 'PMA_HTTP_PORT' "$pma"
        Set-ValorEnv $lineas 'DB_PASS' (New-ClaveBase 32)
        Set-ValorEnv $lineas 'DB_ROOT_PASS' (New-ClaveBase 32)
        Set-ValorEnv $lineas 'ADMIN_PASS' $adminClave
        Set-ValorEnv $lineas 'DEMO_PASS' $demoClave
        Set-ValorEnv $lineas 'SEED_DEMO' $(if ($SinDatosDemo) { '0' } else { '1' })
        Write-Env $envArchivo $lineas
        Escribir-Ok ".env creado con contrasenas al azar (HTTPS $https, HTTP $http, phpMyAdmin $pma)."
    } else {
        $https = [int](Get-ValorEnv $envArchivo 'HTTPS_PORT')
        $http  = [int](Get-ValorEnv $envArchivo 'HTTP_PORT')
        $pma   = [int](Get-ValorEnv $envArchivo 'PMA_HTTP_PORT')
        if ($https -le 0) { $https = 443 }
        if ($http -le 0)  { $http = 80 }
        if ($pma -le 0)   { $pma = 8081 }
        $adminClave = Get-ValorEnv $envArchivo 'ADMIN_PASS'
        Escribir-Ok ".env ya existia: no se toca (HTTPS $https, HTTP $http, phpMyAdmin $pma)."
        $urlEnv = Get-ValorEnv $envArchivo 'APP_URL'
        if ($urlEnv -and ($urlEnv -notmatch [regex]::Escape($ip))) {
            Escribir-Aviso "APP_URL del .env es '$urlEnv', que no usa la IP $ip. Cambielo si va a entrar por $ip y reinicie con: docker compose up -d"
        }
    }
    $base = $(if ($https -eq 443) { "https://$ip" } else { "https://${ip}:$https" })
    $baseHttp = $(if ($http -eq 80) { "http://$ip" } else { "http://${ip}:$http" })

    if ($AbrirFirewall) {
        foreach ($p in @($https, $http)) {
            $nombreRegla = "$($preset.Carpeta) puerto TCP $p"
            if (-not (Get-NetFirewallRule -DisplayName $nombreRegla -ErrorAction SilentlyContinue)) {
                [void](New-NetFirewallRule -DisplayName $nombreRegla -Direction Inbound -Protocol TCP -LocalPort $p -Action Allow -Profile Private, Domain)
                Escribir-Ok "Firewall: regla de entrada para el puerto $p (redes privadas y de dominio)."
            } else { Escribir-Ok "Firewall: ya existia la regla para el puerto $p." }
        }
    }

    if ($NoLevantar) {
        Write-Host ''
        Write-Host 'Se indico -NoLevantar: todo esta preparado. Para levantar el sitio:' -ForegroundColor Yellow
        Write-Host "    cd `"$Destino`"; docker compose up -d --build"
        return
    }

    # ------------------------------------------------------------------ 8. contenedores
    Escribir-Paso 'Construyendo y levantando los contenedores'
    Escribir-Info 'La primera vez la base de datos tarda varios minutos en crearse (y mas con datos de demostracion). No lo interrumpa.'
    $composeBase = @('compose')
    foreach ($f in ($ArchivosCompose -split ',' | Where-Object { $_.Trim() })) {
        if ($composeBase.Count -eq 1) { $composeBase += @('-f', 'docker-compose.yml') }
        $composeBase += @('-f', $f.Trim())
    }
    if ($NombreComposeProyecto) { $composeBase += @('-p', $NombreComposeProyecto) }
    Push-Location $Destino
    try {
        $codigo = Invoke-Nativo { docker @composeBase up -d --build }
        if ($codigo -ne 0) {
            Escribir-Aviso "docker compose up fallo (codigo $codigo); reintento una vez (lo ya descargado o construido no se repite)..."
            Start-Sleep -Seconds 10
            $codigo = Invoke-Nativo { docker @composeBase up -d --build }
        }
        if ($codigo -ne 0) { Fallar "docker compose up fallo (codigo $codigo). Revise el mensaje de arriba (puertos ocupados, red, falta de espacio)." }
        Escribir-Ok 'Contenedores creados.'
        if ($script:CertificadoNuevo -and -not $instalacionNueva) {
            # El contenedor copia el certificado SOLO al arrancar: si ya estaba corriendo, hay que reiniciarlo para que use el nuevo.
            Escribir-Info 'El certificado cambio: reiniciando la app para que lo use...'
            [void](Invoke-Nativo { docker @composeBase restart app })
        }

        # ------------------------------------------------------------ 9. esperar al sitio
        Escribir-Paso 'Esperando a que el sitio responda por HTTPS'
        if (-not $confiable) { [FlexTrust]::Activar($caCrt) }
        $falla = {
            $est = Get-EstadoCompose $composeBase
            $app = $est | Where-Object { $_.Service -eq 'app' } | Select-Object -First 1
            if ($app -and $app.State -in @('exited', 'dead')) {
                Write-Host '--- ultimas lineas del log de la app ---' -ForegroundColor Yellow
                [void](Invoke-Nativo { docker @composeBase logs --tail 40 app })
                Fallar 'El contenedor "app" se detuvo. Revise el log de arriba.'
            }
        }
        $inicio = Wait-Sitio "$base/" $EsperaMinutos $falla
        if (-not $inicio) {
            [void](Invoke-Nativo { docker @composeBase logs --tail 40 app })
            Fallar "El sitio no respondio en $EsperaMinutos minutos en $base/. Revise: docker compose logs app"
        }
        Escribir-Ok "$base/ responde (HTTP $($inicio.Estado))."

        # ------------------------------------------------------------ 10. verificacion
        Escribir-Paso 'Verificando que todo funciona'
        $problemas = 0
        if ($confiable) { Escribir-Ok 'Windows valida el certificado por IP: sin advertencias (la conexion usa el almacen de Windows).' }
        else { Escribir-Aviso 'Windows todavia no confia en la CA (se uso -NoInstalarCa): el certificado se verifico contra ssl\ca.crt, pero el navegador avisara.' }

        foreach ($h in @('localhost', '127.0.0.1')) {
            $u = if ($https -eq 443) { "https://$h/" } else { "https://${h}:$https/" }
            $r = Invoke-Web $u
            if ($r.Estado -ge 200 -and $r.Estado -lt 400) { Escribir-Ok "$u responde y el certificado coincide con $h." }
            else { Escribir-Aviso "$u no valido ($(if ($r.Estado) { 'HTTP ' + $r.Estado } else { $r.Error }))."; $problemas++ }
        }

        $api = Invoke-Web "$base/api/torneos"
        if ($api.Estado -eq 200 -and $api.Contenido -match '"ok"\s*:\s*true') { Escribir-Ok 'La API publica responde (/api/torneos).' }
        else { Escribir-Aviso "La API no respondio como se esperaba (HTTP $($api.Estado))."; $problemas++ }

        $red = Invoke-Web "$baseHttp/" $null 'GET' '' $false
        if ($red.Estado -in @(301, 302, 307, 308) -and $red.Ubicacion -like 'https://*') { Escribir-Ok "HTTP ($baseHttp) redirige a HTTPS." }
        else { Escribir-Aviso "El puerto HTTP no redirigio a HTTPS (estado $($red.Estado))."; $problemas++ }

        if ($instalacionNueva -and $AdminEmail -and $adminClave) {
            $cj = New-Object System.Net.CookieContainer
            $form = Invoke-Web "$base/login" $cj
            if ($form.Estado -eq 200 -and $form.Contenido -match 'name="csrf_token"\s+value="([^"]+)"') {
                $token = $Matches[1]
                $datos = ConvertTo-Formulario @{ csrf_token = $token; email = $AdminEmail; password = $adminClave }
                $post = Invoke-Web "$base/login" $cj 'POST' $datos $false
                if ($post.Estado -in @(302, 303) -and $post.Ubicacion -match '/admin') {
                    $panel = Invoke-Web "$base/admin" $cj
                    if ($panel.Estado -eq 200) { Escribir-Ok "Inicio de sesion del administrador ($AdminEmail) y acceso al panel: bien." }
                    else { Escribir-Aviso "Inicio de sesion correcto, pero /admin dio HTTP $($panel.Estado)."; $problemas++ }
                } else { Escribir-Aviso "El inicio de sesion del administrador no funciono (HTTP $($post.Estado) -> '$($post.Ubicacion)')."; $problemas++ }
            } else { Escribir-Aviso 'No pude leer el formulario de inicio de sesion.'; $problemas++ }
        } elseif (-not $instalacionNueva) {
            Escribir-Info 'Instalacion existente: no se prueba el inicio de sesion (para no contar intentos fallidos).'
        }

        Write-Host ''
        if ($problemas -eq 0) { Write-Host "=== $Proyecto instalado y funcionando ===" -ForegroundColor Green }
        else { Write-Host "=== $Proyecto instalado, con $problemas aviso(s) para revisar ===" -ForegroundColor Yellow }
        Write-Host ''
        Write-Host "  Sitio:        $base/"
        Write-Host "  Panel admin:  $base/admin"
        if ($AdminEmail) { Write-Host "  Usuario:      $AdminEmail" }
        if ($instalacionNueva -and $adminClave) { Write-Host "  Contrasena:   $adminClave   (tambien esta en el archivo .env: ADMIN_PASS)" -ForegroundColor White }
        Write-Host "  phpMyAdmin:   http://127.0.0.1:$pma/   (solo desde esta PC)"
        Write-Host "  Carpeta:      $Destino"
        Write-Host ''
        Write-Host '  Otras PCs Windows: copiar ssl\ca.crt de la carpeta anterior y ejecutar alli:'
        Write-Host '      powershell -ExecutionPolicy Bypass -File instalar_windows.ps1 -SoloCertificado -CaArchivo ca.crt'
        if (-not $AbrirFirewall) { Write-Host "  Para que otras PCs entren por $ip, vuelva a ejecutar con -AbrirFirewall (o abra el puerto $https en el firewall)." }
        Write-Host ''
        Write-Host '  Detener:      docker compose stop      Iniciar: docker compose start      Logs: docker compose logs -f app'
    } finally {
        [FlexTrust]::Desactivar()
        Pop-Location
    }
}

# Al "dot-sourcear" el archivo (. .\instalar_windows.ps1) solo se cargan las funciones; no se instala nada.
if ($MyInvocation.InvocationName -ne '.') {
    $codigoSalida = 0
    try {
        Start-Instalacion
    } catch {
        Write-Host ''
        Write-Host ('ERROR: ' + $_.Exception.Message) -ForegroundColor Red
        $codigoSalida = 1
    }
    if ($ElevadoPorScript -and -not $NoPausar) {
        Write-Host ''
        [void](Read-Host 'Presione Enter para cerrar esta ventana')
    }
    exit $codigoSalida
}
