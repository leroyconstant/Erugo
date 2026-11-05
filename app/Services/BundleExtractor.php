<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

class BundleExtractor
{
    private function safePath(string $path): string
    {
        if ($path === '' || strlen($path) > 4096 || str_contains($path, "\0")
            || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new RuntimeException('Invalid archive path');
        }
        foreach (explode('/', rtrim($path, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('Invalid archive path');
            }
        }
        return $path;
    }

    /** Extract only manifest files, with bounded reads and server-measured sizes. */
    public function extract(string $archive, string $directory, ?int $shareLimit = null): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('Invalid bundle archive');
        }
        try {
            $maxFiles = max(1, (int) config('erugo.bundle_max_files'));
            $limit = max(1, (int) config('erugo.bundle_max_bytes'));
            if ($shareLimit !== null) {
                $limit = min($limit, max(0, $shareLimit));
            }
            $free = disk_free_space($directory);
            if ($free !== false) {
                $limit = min($limit, max(0, (int) $free - 67108864));
            }
            if ($zip->numFiles > $maxFiles + 1) {
                throw new RuntimeException('Too many archive entries');
            }
            $entries = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new RuntimeException('Invalid archive entry');
                }
                $name = $this->safePath($stat['name']);
                if (isset($entries[$name])) {
                    throw new RuntimeException('Duplicate archive path');
                }
                $zip->getExternalAttributesIndex($index, $opsys, $attributes);
                if ((($attributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('Archive symlinks are forbidden');
                }
                $total += $stat['size'];
                // Include manifest bytes in the disk budget as well.
                if ($total > $limit || $stat['size'] > max(1, $stat['comp_size']) * max(1, (int) config('erugo.bundle_max_ratio'))) {
                    throw new RuntimeException('Archive expansion limit exceeded');
                }
                $entries[$name] = $stat;
            }
            $manifestStat = $entries['__erugo_manifest__.json'] ?? null;
            if (!$manifestStat || $manifestStat['size'] > config('erugo.bundle_manifest_max_bytes')) {
                throw new RuntimeException('Missing or oversized bundle manifest');
            }
            $manifestLimit = (int) config('erugo.bundle_manifest_max_bytes');
            $manifestJson = $zip->getFromName('__erugo_manifest__.json', $manifestLimit + 1);
            if (!is_string($manifestJson) || strlen($manifestJson) > $manifestLimit
                || strlen($manifestJson) !== $manifestStat['size']) {
                throw new RuntimeException('Invalid or oversized bundle manifest');
            }
            $manifest = json_decode($manifestJson, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($manifest['files'] ?? null) || count($manifest['files']) > $maxFiles || !$manifest['files']) {
                throw new RuntimeException('Invalid bundle manifest');
            }
            $files = [];
            $seen = [];
            $written = 0;
            foreach ($manifest['files'] as $file) {
                if (!is_array($file) || !is_string($file['path'] ?? null) || !is_string($file['originalName'] ?? null) || strlen($file['originalName']) > 255) {
                    throw new RuntimeException('Invalid bundle file');
                }
                $path = $this->safePath($file['path']);
                if (isset($seen[$path]) || $path === '__erugo_manifest__.json' || str_ends_with($path, '/') || !isset($entries[$path])) {
                    throw new RuntimeException('Invalid or duplicate bundle file');
                }
                $seen[$path] = true;
                $destination = $directory . '/' . $path;
                if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true)) {
                    throw new RuntimeException('Cannot create bundle directory');
                }
                $input = $zip->getStream($path);
                $output = fopen($destination, 'xb');
                if (!$input || !$output) {
                    if (is_resource($input)) fclose($input);
                    if (is_resource($output)) fclose($output);
                    throw new RuntimeException('Cannot extract bundle file');
                }
                $size = 0;
                try {
                    while (!feof($input)) {
                        $chunk = fread($input, 65536);
                        if ($chunk === false) {
                            throw new RuntimeException('Cannot read bundle file');
                        }
                        $size += strlen($chunk);
                        $written += strlen($chunk);
                        if ($written > $limit || $size > $entries[$path]['size']) {
                            throw new RuntimeException('Archive expansion limit exceeded');
                        }
                        if (fwrite($output, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException('Cannot write bundle file');
                        }
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                if ($size !== $entries[$path]['size']) {
                    throw new RuntimeException('Invalid bundle file size');
                }
                $files[] = [
                    'path' => $path, 'originalName' => $file['originalName'], 'size' => $size,
                    'type' => is_string($file['type'] ?? null) ? $file['type'] : 'application/octet-stream',
                ];
            }
            return $files;
        } finally {
            $zip->close();
        }
    }
}
