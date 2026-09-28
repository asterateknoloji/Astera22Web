param(
    [string]$SourceDirectory = "$PSScriptRoot\..\build\native\pjproject"
)

$ErrorActionPreference = "Stop"
$Version = "2.17"
$Commit = "5a457451fa2712ba18e12b01738e8ff3af2b26fd"
$ProjectRoot = (Resolve-Path "$PSScriptRoot\..").Path
$NativeRoot = Join-Path $ProjectRoot "native\pjsip"
$WindowsRoot = Join-Path $NativeRoot "windows"

$VsWhere = "${env:ProgramFiles(x86)}\Microsoft Visual Studio\Installer\vswhere.exe"
if (-not (Test-Path $VsWhere)) {
    throw "Visual Studio Build Tools 2022 is required."
}

$VisualStudio = & $VsWhere -latest -products * `
    -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 `
    -property installationPath
$MsBuild = Join-Path $VisualStudio "MSBuild\Current\Bin\MSBuild.exe"

if (-not (Test-Path (Join-Path $SourceDirectory ".git"))) {
    New-Item -ItemType Directory -Force -Path (Split-Path $SourceDirectory) | Out-Null
    git clone https://github.com/pjsip/pjproject.git $SourceDirectory
}

git -C $SourceDirectory fetch --tags --force
git -C $SourceDirectory checkout --detach $Commit

$ConfigSite = Join-Path $SourceDirectory "pjlib\include\pj\config_site.h"
@"
#pragma once
#define PJMEDIA_HAS_VIDEO 0
#define PJMEDIA_HAS_OPENH264_CODEC 0
"@ | Set-Content -Encoding Ascii $ConfigSite

& $MsBuild (Join-Path $SourceDirectory "pjproject-vs14.sln") `
    "/t:pjsua2_lib;libpjproject" `
    /m `
    /p:Configuration=Release `
    /p:Platform=x64 `
    /p:PlatformToolset=v143 `
    /p:WindowsTargetPlatformVersion=10.0 `
    /v:minimal

New-Item -ItemType Directory -Force `
    -Path "$WindowsRoot\include", "$WindowsRoot\lib" | Out-Null

foreach ($IncludeDirectory in @(
    "pjlib\include",
    "pjlib-util\include",
    "pjmedia\include",
    "pjnath\include",
    "pjsip\include"
)) {
    Copy-Item (Join-Path $SourceDirectory "$IncludeDirectory\*") `
        "$WindowsRoot\include" -Recurse -Force
}

Copy-Item `
    (Join-Path $SourceDirectory "pjsip\lib\pjsua2-lib-x86_64-x64-vc14-Release.lib") `
    "$WindowsRoot\lib" -Force
Copy-Item `
    (Join-Path $SourceDirectory "lib\libpjproject-x86_64-x64-vc14-Release.lib") `
    "$WindowsRoot\lib" -Force
Copy-Item (Join-Path $SourceDirectory "COPYING") $NativeRoot -Force

@"
PJSIP_VERSION=$Version
PJSIP_COMMIT=$Commit
TARGET=windows-x64
RUNTIME=MultiThreadedDLL
VIDEO=disabled
"@ | Set-Content -Encoding Ascii (Join-Path $NativeRoot "VERSION")

Write-Host "PJSIP $Version Windows x64 artifacts staged in $WindowsRoot"
