<?php

namespace App\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;

/**
 * Versions static assets (public/asset/...) using a short hash of the file's
 * own content, so the URL changes automatically whenever the file changes on
 * deploy, without any manual version bump or build step.
 */
final class FileHashVersionStrategy implements VersionStrategyInterface
{
    private array $hashes = [];

    public function __construct(private readonly string $publicDir)
    {
    }

    public function getVersion(string $path): string
    {
        return $this->hash($path);
    }

    public function applyVersion(string $path): string
    {
        $hash = $this->hash($path);

        if ('' === $hash) {
            return $path;
        }

        return $path.(str_contains($path, '?') ? '&' : '?').'v='.$hash;
    }

    private function hash(string $path): string
    {
        if (isset($this->hashes[$path])) {
            return $this->hashes[$path];
        }

        $file = $this->publicDir.'/'.ltrim($path, '/');

        if (!is_file($file)) {
            return $this->hashes[$path] = '';
        }

        return $this->hashes[$path] = substr(md5_file($file), 0, 8);
    }
}
