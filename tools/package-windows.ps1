# Quiesce — stage a Windows build.
#
# ⚠ NEVER EXECUTED. Written on Linux; there is no Windows machine here to run it
# on. The layout is what the compiled build produces (build/windows/amd64), and it
# is staged so an installer can pick it up. Until somebody runs this on Windows,
# treat it as a plan rather than a product.
#
#   pwsh tools/package-windows.ps1
#
# For a real installer, Inno Setup takes the staged folder:
#
#   [Setup]
#   AppName=Quiesce
#   AppVersion=<version>
#   DefaultDirName={autopf}\Quiesce
#   [Files]
#   Source: "dist\windows\*"; DestDir: "{app}"; Flags: recursesubdirs
#   [Icons]
#   Name: "{autoprograms}\Quiesce"; Filename: "{app}\quiesce.exe"
#
# Signing needs a certificate this project does not have:
#   signtool sign /fd SHA256 /tr http://timestamp.digicert.com /td SHA256 quiesce.exe

$ErrorActionPreference = "Stop"

$here = Split-Path -Parent $PSScriptRoot
$version = (Get-Content "$here\VERSION" -Raw).Trim()
$source = "$here\build\windows\amd64"
$target = "$here\dist\windows"

if (-not (Test-Path "$source\quiesce.exe")) {
    Write-Error "nothing built at $source\quiesce.exe — run: php vendor/bin/boson compile"
}

Remove-Item -Recurse -Force $target -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path "$target\tools" | Out-Null

Copy-Item "$source\quiesce.exe" $target
Copy-Item "$source\*.dll" $target -ErrorAction SilentlyContinue

Copy-Item "$here\tools\*.php" "$target\tools\" -ErrorAction SilentlyContinue
Copy-Item "$here\tools\*.sh" "$target\tools\" -ErrorAction SilentlyContinue

$version | Set-Content "$target\VERSION"

Write-Host "staged $target (version $version)"
Write-Host "next: build an installer from that folder (see the header of this file)."
Write-Host "note: not signed."
