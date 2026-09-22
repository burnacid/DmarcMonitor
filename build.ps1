<#
.SYNOPSIS
    Bumps the app version, commits and tags it, then builds and pushes the
    Docker image for that version to Docker Hub.

.DESCRIPTION
    Reads the current version from the VERSION file, asks which part to
    increase (Major / Minor / Revision), writes the new version back,
    commits it, tags the commit, and (after confirmation) pushes the image
    as burnacid/dmarc-monitor:<version> and :latest.

.EXAMPLE
    ./build.ps1
#>

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

$versionFile = Join-Path $PSScriptRoot 'VERSION'
$imageName = 'burnacid/dmarc-monitor'

function Invoke-Native {
    param(
        [Parameter(Mandatory)][string]$Exe,
        [Parameter(Mandatory)][string[]]$Arguments,
        [string]$ErrorMessage = "Command failed: $Exe $($Arguments -join ' ')"
    )

    & $Exe @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw $ErrorMessage
    }
}

function Confirm-Step {
    param([Parameter(Mandatory)][string]$Message)

    $answer = Read-Host "$Message [y/N]"
    return $answer -match '^(y|yes)$'
}

# --- Preconditions ----------------------------------------------------------

if (-not (Test-Path $versionFile)) {
    throw "VERSION file not found at $versionFile"
}

$gitStatus = git status --porcelain
if ($LASTEXITCODE -ne 0) {
    throw 'Not a git repository (or git is not on PATH).'
}
if ($gitStatus) {
    Write-Host "Working tree is not clean:" -ForegroundColor Yellow
    Write-Host $gitStatus
    throw 'Commit or stash your changes before running the version bump.'
}

$currentBranch = (git rev-parse --abbrev-ref HEAD).Trim()

# --- Read current version ---------------------------------------------------

$currentVersion = (Get-Content $versionFile -Raw).Trim()
if ($currentVersion -notmatch '^(\d+)\.(\d+)\.(\d+)$') {
    throw "VERSION file does not contain a valid Major.Minor.Revision version: '$currentVersion'"
}
$major = [int]$Matches[1]
$minor = [int]$Matches[2]
$revision = [int]$Matches[3]

Write-Host "Current version: $currentVersion (branch: $currentBranch)" -ForegroundColor Cyan

# --- Ask which part to bump --------------------------------------------------

do {
    $choice = Read-Host 'Bump which part? [M]ajor / [N]Minor / [R]evision'
    $choice = $choice.Trim().ToUpperInvariant()
} while ($choice -notin @('M', 'MAJOR', 'N', 'MINOR', 'R', 'REVISION'))

switch -Regex ($choice) {
    '^M' { $major++; $minor = 0; $revision = 0 }
    '^N' { $minor++; $revision = 0 }
    '^R' { $revision++ }
}

$newVersion = "$major.$minor.$revision"

if (-not (Confirm-Step "Bump version $currentVersion -> $newVersion, commit, tag, then build & push docker image?")) {
    Write-Host 'Aborted.' -ForegroundColor Yellow
    exit 1
}

# --- Write, commit, tag ------------------------------------------------------

Set-Content -Path $versionFile -Value $newVersion -NoNewline
Add-Content -Path $versionFile -Value ''

Invoke-Native -Exe 'git' -Arguments @('add', 'VERSION')
Invoke-Native -Exe 'git' -Arguments @('commit', '-m', "Bump version to $newVersion")
Invoke-Native -Exe 'git' -Arguments @('tag', $newVersion)

Write-Host "Committed and tagged $newVersion." -ForegroundColor Green

if (Confirm-Step "Push commit and tag $newVersion to origin/$currentBranch now?") {
    Invoke-Native -Exe 'git' -Arguments @('push', 'origin', $currentBranch)
    Invoke-Native -Exe 'git' -Arguments @('push', 'origin', $newVersion)
    Write-Host 'Pushed.' -ForegroundColor Green
} else {
    Write-Host 'Skipped push; remember to push the commit and tag manually.' -ForegroundColor Yellow
}

# --- Merge to main ------------------------------------------------------------

$buildBranch = $currentBranch

if ($currentBranch -ne 'main') {
    if (Confirm-Step "Merge $currentBranch into main and push main?") {
        Invoke-Native -Exe 'git' -Arguments @('fetch', 'origin', 'main')
        Invoke-Native -Exe 'git' -Arguments @('checkout', 'main')

        try {
            Invoke-Native -Exe 'git' -Arguments @('pull', 'origin', 'main', '--ff-only')
            Invoke-Native -Exe 'git' -Arguments @('merge', '--no-ff', $currentBranch, '-m', "Merge $currentBranch into main for $newVersion")
        } catch {
            Write-Host 'Merge into main failed; aborting merge and returning to your branch. Resolve manually.' -ForegroundColor Red
            git merge --abort 2>$null
            git checkout $currentBranch 2>$null
            throw
        }

        Invoke-Native -Exe 'git' -Arguments @('push', 'origin', 'main')
        Write-Host 'Merged and pushed main.' -ForegroundColor Green
        $buildBranch = 'main'
    } else {
        Write-Host 'Skipped merge to main.' -ForegroundColor Yellow
    }
}

# --- Build and push the Docker image -----------------------------------------

if (-not (Confirm-Step "Build and push ${imageName}:${newVersion} and ${imageName}:latest from '$buildBranch' to Docker Hub?")) {
    Write-Host 'Skipped Docker build/push. Version bump is already committed and tagged.' -ForegroundColor Yellow
    if ($buildBranch -ne $currentBranch) {
        git checkout $currentBranch 2>$null
    }
    exit 0
}

try {
    Invoke-Native -Exe 'docker' -Arguments @(
        'buildx', 'build',
        '--platform', 'linux/amd64',
        '-t', "${imageName}:${newVersion}",
        '-t', "${imageName}:latest",
        '--push',
        '.'
    )
    Write-Host "Published ${imageName}:${newVersion} (and :latest)." -ForegroundColor Green
} finally {
    if ($buildBranch -ne $currentBranch) {
        git checkout $currentBranch 2>$null
    }
}
