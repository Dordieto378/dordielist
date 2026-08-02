param(
    [Parameter(Mandatory = $true)]
    [string] $Source
)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
$OutputEncoding = [Console]::OutputEncoding
Add-Type -AssemblyName System.IO.Compression.FileSystem

$imageExtensions = @('.jpg', '.jpeg', '.png', '.webp', '.gif')
$results = @()

Get-ChildItem -LiteralPath $Source -Recurse -File -Filter *.zip |
    Sort-Object FullName |
    ForEach-Object {
        $archive = $_
        $zip = $null
        $images = 0
        $chapters = @{}
        $errorMessage = $null

        try {
            $zip = [System.IO.Compression.ZipFile]::OpenRead($archive.FullName)
            $seenPaths = @{}

            foreach ($entry in $zip.Entries) {
                $name = $entry.FullName.Replace('\', '/')

                if (
                    [string]::IsNullOrWhiteSpace($name) -or
                    $name.Contains([char] 0) -or
                    $name -match '(^/)|(^[A-Za-z]:/)|(^|/)\.\.(/|$)'
                ) {
                    throw "Unsafe archive entry: $name"
                }

                $normalizedPath = $name.TrimEnd('/').ToLowerInvariant()
                if ($normalizedPath -ne '') {
                    if ($seenPaths.ContainsKey($normalizedPath)) {
                        throw "Duplicate archive entry: $name"
                    }
                    $seenPaths[$normalizedPath] = $true
                }

                if ($name.EndsWith('/')) {
                    continue
                }

                $extension = [System.IO.Path]::GetExtension($name).ToLowerInvariant()
                if ($imageExtensions -notcontains $extension) {
                    continue
                }

                $segments = @($name.Trim('/').Split('/') | Where-Object { $_ -ne '' })
                if ($segments.Count -ne 2) {
                    throw "Images must be inside one chapter folder; invalid entry: $name"
                }

                $chapters[$segments[0].ToLowerInvariant()] = $true
                $images++
            }

            if ($images -eq 0 -or $chapters.Count -eq 0) {
                throw 'The archive does not contain any chapter images.'
            }
        } catch {
            $errorMessage = $_.Exception.Message
        } finally {
            if ($null -ne $zip) {
                $zip.Dispose()
            }
        }

        $results += [PSCustomObject]@{
            path = $archive.FullName
            images = $images
            chapters = $chapters.Count
            error = $errorMessage
        }
    }

$results | ConvertTo-Json -Compress -Depth 3
