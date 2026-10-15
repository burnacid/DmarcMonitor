<#
.SYNOPSIS
    Bumps the app version, commits and tags it, then builds and pushes the
    Docker image for that version to Docker Hub.

.DESCRIPTION
    Reads the current version from the VERSION file and asks whether to bump
    it (Major / Minor / Revision — writes the new version back, commits and
    tags it) or rebuild the existing version as-is. The tag is annotated with
    that version's section of CHANGELOG.md; if there is no such section, an
    [Unreleased] section is renamed to the new version in the same commit.
    After pushing, it can create a GitHub release for the tag with the same
    notes (via the gh CLI, or GITHUB_TOKEN/GH_TOKEN when gh isn't installed). Either way, after
    confirmation it can merge to main and builds & pushes the image as
    burnacid/dmarc-monitor:<version> and :latest.

.EXAMPLE
    ./build.ps1
#>

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

$versionFile = Join-Path $PSScriptRoot 'VERSION'
$changelogFile = Join-Path $PSScriptRoot 'CHANGELOG.md'
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

# Creates a GitHub release for an already pushed tag, with the changelog
# section as its notes. Uses the gh CLI when installed (and logged in),
# otherwise the GitHub REST API with a token from GITHUB_TOKEN or GH_TOKEN.
function New-GitHubRelease {
    param(
        [Parameter(Mandatory)][string]$Version,
        [string]$Notes
    )

    $body = if ($Notes) { $Notes } else { "Version $Version" }

    if (Get-Command 'gh' -ErrorAction SilentlyContinue) {
        $notesFile = [IO.Path]::GetTempFileName()
        try {
            [IO.File]::WriteAllText($notesFile, $body, (New-Object System.Text.UTF8Encoding($false)))
            Invoke-Native -Exe 'gh' -Arguments @('release', 'create', $Version, '--verify-tag', '--title', $Version, '--notes-file', $notesFile)
        } finally {
            Remove-Item -Path $notesFile -Force -ErrorAction SilentlyContinue
        }

        Write-Host "Created GitHub release $Version." -ForegroundColor Green

        return
    }

    $token = if ($env:GITHUB_TOKEN) { $env:GITHUB_TOKEN } else { $env:GH_TOKEN }
    if (-not $token) {
        throw 'Neither the gh CLI (winget install GitHub.cli, then gh auth login) nor a GITHUB_TOKEN/GH_TOKEN environment variable is available.'
    }

    $remoteUrl = (git remote get-url origin).Trim()
    if ($remoteUrl -notmatch 'github\.com[:/](?<owner>[^/]+)/(?<repo>[^/]+?)(\.git)?$') {
        throw "origin ($remoteUrl) is not a GitHub repository."
    }

    $response = Invoke-RestMethod -Method Post `
        -Uri "https://api.github.com/repos/$($Matches.owner)/$($Matches.repo)/releases" `
        -Headers @{
            Authorization = "Bearer $token"
            Accept = 'application/vnd.github+json'
            'X-GitHub-Api-Version' = '2022-11-28'
        } `
        -ContentType 'application/json; charset=utf-8' `
        -Body ([Text.Encoding]::UTF8.GetBytes((@{ tag_name = $Version; name = $Version; body = $body } | ConvertTo-Json)))

    Write-Host "Created GitHub release $Version`: $($response.html_url)" -ForegroundColor Green
}

# Returns the body of the "## [<Heading>]" section of the changelog (up to the
# next "## " heading), or $null when there is no such section.
function Get-ChangelogSection {
    param(
        [Parameter(Mandatory)][string]$Content,
        [Parameter(Mandatory)][string]$Heading
    )

    $lines = $Content -split "`r?`n"
    $start = -1

    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match "^##\s+\[$([regex]::Escape($Heading))\]\s*$") {
            $start = $i + 1
            break
        }
    }

    if ($start -lt 0) {
        return $null
    }

    $end = $lines.Count
    for ($i = $start; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match '^##\s') {
            $end = $i
            break
        }
    }

    if ($end -le $start) {
        return ''
    }

    return ($lines[$start..($end - 1)] -join "`n").Trim()
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

# --- Ask whether to bump the version at all ----------------------------------

do {
    $doBump = Read-Host "Bump the version, or rebuild existing $currentVersion as-is? [B]ump / [R]ebuild"
    $doBump = $doBump.Trim().ToUpperInvariant()
} while ($doBump -notin @('B', 'BUMP', 'R', 'REBUILD'))

if ($doBump.StartsWith('B')) {

    # --- Ask which part to bump ----------------------------------------------

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

    # --- Find the release notes in the changelog -------------------------------

    # Read and written as UTF-8 without BOM explicitly: Windows PowerShell's
    # Get-Content/Set-Content would otherwise use the ANSI code page.
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    $changelogContent = if (Test-Path $changelogFile) { [IO.File]::ReadAllText($changelogFile, $utf8NoBom) } else { $null }
    $releaseNotes = $null
    $renameUnreleased = $false

    if ($null -eq $changelogContent) {
        Write-Host "No CHANGELOG.md found; the tag will have no release notes." -ForegroundColor Yellow
    } else {
        $releaseNotes = Get-ChangelogSection -Content $changelogContent -Heading $newVersion
        $unreleasedNotes = Get-ChangelogSection -Content $changelogContent -Heading 'Unreleased'

        if ($null -eq $releaseNotes -and $null -ne $unreleasedNotes) {
            # The [Unreleased] section becomes this version's section.
            $releaseNotes = $unreleasedNotes
            $renameUnreleased = $true
            Write-Host "CHANGELOG.md: [Unreleased] will be renamed to [$newVersion]." -ForegroundColor Cyan
        } elseif ($null -ne $releaseNotes -and $null -ne $unreleasedNotes) {
            Write-Host "CHANGELOG.md already has a [$newVersion] section; [Unreleased] is left as it is." -ForegroundColor Yellow
        } elseif ($null -eq $releaseNotes) {
            Write-Host "CHANGELOG.md has no [$newVersion] or [Unreleased] section." -ForegroundColor Yellow
        }

        if (-not $releaseNotes) {
            $releaseNotes = $null
            if (-not (Confirm-Step "Continue without release notes in the tag?")) {
                Write-Host 'Aborted.' -ForegroundColor Yellow
                exit 1
            }
        }
    }

    if ($releaseNotes) {
        Write-Host ''
        Write-Host "Release notes for ${newVersion}:" -ForegroundColor Cyan
        Write-Host $releaseNotes
        Write-Host ''
    }

    if (-not (Confirm-Step "Bump version $currentVersion -> $newVersion, commit, tag, then build & push docker image?")) {
        Write-Host 'Aborted.' -ForegroundColor Yellow
        exit 1
    }

    # --- Write, commit, tag ---------------------------------------------------

    Set-Content -Path $versionFile -Value $newVersion -NoNewline
    Add-Content -Path $versionFile -Value ''
    Invoke-Native -Exe 'git' -Arguments @('add', 'VERSION')

    if ($renameUnreleased) {
        $renamedChangelog = [regex]::Replace($changelogContent, '(?m)^##[ \t]+\[Unreleased\][ \t]*(?=\r?$)', "## [$newVersion]", 1)
        [IO.File]::WriteAllText($changelogFile, $renamedChangelog, $utf8NoBom)
        Invoke-Native -Exe 'git' -Arguments @('add', 'CHANGELOG.md')
    }

    Invoke-Native -Exe 'git' -Arguments @('commit', '-m', "Bump version to $newVersion")

    if ($releaseNotes) {
        # Annotated tag carrying the release notes. --cleanup=whitespace keeps
        # the "### Added" style headings, which the default mode would strip
        # as comment lines.
        $tagMessageFile = [IO.Path]::GetTempFileName()
        try {
            [IO.File]::WriteAllText($tagMessageFile, "Version $newVersion`n`n$releaseNotes`n", $utf8NoBom)
            Invoke-Native -Exe 'git' -Arguments @('tag', '-a', $newVersion, '-F', $tagMessageFile, '--cleanup=whitespace')
        } finally {
            Remove-Item -Path $tagMessageFile -Force -ErrorAction SilentlyContinue
        }
    } else {
        Invoke-Native -Exe 'git' -Arguments @('tag', '-a', $newVersion, '-m', "Version $newVersion")
    }

    Write-Host "Committed and tagged $newVersion." -ForegroundColor Green

    if (Confirm-Step "Push commit and tag $newVersion to origin/$currentBranch now?") {
        Invoke-Native -Exe 'git' -Arguments @('push', 'origin', $currentBranch)
        Invoke-Native -Exe 'git' -Arguments @('push', 'origin', $newVersion)
        Write-Host 'Pushed.' -ForegroundColor Green

        if (Confirm-Step "Create GitHub release $newVersion from the tag?") {
            try {
                New-GitHubRelease -Version $newVersion -Notes $releaseNotes
            } catch {
                Write-Host "Creating the GitHub release failed: $($_.Exception.Message)" -ForegroundColor Red
                Write-Host 'The tag is pushed; create the release manually on GitHub.' -ForegroundColor Yellow
            }
        } else {
            Write-Host 'Skipped GitHub release.' -ForegroundColor Yellow
        }
    } else {
        Write-Host 'Skipped push (and GitHub release); remember to push the commit and tag manually.' -ForegroundColor Yellow
    }
} else {
    $newVersion = $currentVersion

    if (-not (Confirm-Step "Rebuild existing version $newVersion without bumping?")) {
        Write-Host 'Aborted.' -ForegroundColor Yellow
        exit 1
    }

    Write-Host "Rebuilding $newVersion; no version bump, commit or tag." -ForegroundColor Cyan
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
