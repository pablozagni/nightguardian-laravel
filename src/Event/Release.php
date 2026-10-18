<?php

namespace NightGuardian\Laravel\Event;

use Throwable;

/**
 * Release identifier: NIGHTGUARDIAN_RELEASE, else the short git SHA found in `.git`, else null.
 *
 * The git lookup is cached in `<storage>/release` and invalidated when `.git/HEAD` or the branch ref
 * file changes (a deploy), so a normal request costs two stat() calls and one small file read.
 */
class Release
{
    /**
     * @var string|null
     */
    private $configured;

    /**
     * @var string
     */
    private $basePath;

    /**
     * @var string|null
     */
    private $storagePath;

    /**
     * @var string|null
     */
    private $resolved;

    /**
     * @var bool
     */
    private $isResolved = false;

    public function __construct(?string $configured, string $basePath, ?string $storagePath = null)
    {
        $this->configured = $configured;
        $this->basePath = $basePath;
        $this->storagePath = $storagePath;
    }

    public function resolve(): ?string
    {
        if ($this->isResolved) {
            return $this->resolved;
        }

        $this->isResolved = true;

        try {
            if (is_string($this->configured) && trim($this->configured) !== '') {
                return $this->resolved = mb_substr(trim($this->configured), 0, 128);
            }

            return $this->resolved = $this->fromGit();
        } catch (Throwable $e) {
            return $this->resolved = null;
        }
    }

    /**
     * Full commit SHA and branch name from `.git`, uncached (used by `nightguardian:deploy`).
     *
     * @return array{sha: ?string, branch: ?string}
     */
    public function gitInfo(): array
    {
        try {
            $gitDir = $this->gitDirectory();

            if ($gitDir === null) {
                return ['sha' => null, 'branch' => null];
            }

            $head = trim((string) @file_get_contents($gitDir.'/HEAD'));
            $isRef = strncmp($head, 'ref:', strlen('ref:')) === 0;
            $ref = $isRef ? trim(substr($head, 4)) : null;

            return [
                'sha' => $this->readSha($gitDir, $head, $ref !== null ? $gitDir.'/'.$ref : null),
                'branch' => $ref !== null ? (preg_replace('#^refs/heads/#', '', $ref) ?? $ref) : null,
            ];
        } catch (Throwable $e) {
            return ['sha' => null, 'branch' => null];
        }
    }

    private function fromGit(): ?string
    {
        $gitDir = $this->gitDirectory();

        if ($gitDir === null) {
            return null;
        }

        $head = $gitDir.'/HEAD';
        $refFile = null;
        $headContents = null;
        $stamp = (string) @filemtime($head);

        $cache = $this->readCache();

        // The ref file path comes from HEAD, which is cheap to read; its mtime is part of the cache key.
        $headContents = trim((string) @file_get_contents($head));

        if (strncmp($headContents, 'ref:', strlen('ref:')) === 0) {
            $refFile = $gitDir.'/'.trim(substr($headContents, 4));
            $stamp .= ':'.(string) @filemtime($refFile).':'.(string) @filemtime($gitDir.'/packed-refs');
        }

        if ($cache !== null && $cache['stamp'] === $stamp) {
            return $cache['release'];
        }

        $sha = $this->readSha($gitDir, $headContents, $refFile);
        $release = $sha !== null ? substr($sha, 0, 7) : null;

        $this->writeCache($stamp, $release);

        return $release;
    }

    private function readSha(string $gitDir, string $headContents, ?string $refFile): ?string
    {
        if ($refFile === null) {
            return $this->isSha($headContents) ? $headContents : null; // detached HEAD
        }

        $sha = is_file($refFile) ? trim((string) @file_get_contents($refFile)) : '';

        if ($this->isSha($sha)) {
            return $sha;
        }

        // The branch may only exist in packed-refs.
        $ref = trim(substr($headContents, 4));
        $packed = @file($gitDir.'/packed-refs', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($packed ?: [] as $line) {
            if ($line !== '' && $line[0] !== '#' && $line[0] !== '^' && substr_compare($line, ' '.$ref, -strlen(' '.$ref)) === 0) {
                $candidate = trim(substr($line, 0, 40));

                return $this->isSha($candidate) ? $candidate : null;
            }
        }

        return null;
    }

    private function gitDirectory(): ?string
    {
        $dotGit = rtrim(str_replace('\\', '/', $this->basePath), '/').'/.git';

        if (is_dir($dotGit)) {
            return $dotGit;
        }

        // Worktrees and submodules: ".git" is a file containing "gitdir: <path>".
        if (is_file($dotGit)) {
            $contents = trim((string) @file_get_contents($dotGit));

            if (strncmp($contents, 'gitdir:', strlen('gitdir:')) === 0) {
                $path = trim(substr($contents, 7));
                $absolute = preg_match('#^([a-zA-Z]:)?[/\\\\]#', $path) === 1;
                $path = $absolute ? $path : dirname($dotGit).'/'.$path;

                return is_dir($path) ? str_replace('\\', '/', $path) : null;
            }
        }

        return null;
    }

    private function isSha(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40}$/i', $value) === 1;
    }

    /**
     * @return array{stamp: string, release: ?string}|null
     */
    private function readCache(): ?array
    {
        if ($this->storagePath === null) {
            return null;
        }

        $raw = @file_get_contents($this->storagePath.'/release');
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($data) || ! isset($data['stamp']) || ! array_key_exists('release', $data)) {
            return null;
        }

        return ['stamp' => (string) $data['stamp'], 'release' => is_string($data['release']) ? $data['release'] : null];
    }

    private function writeCache(string $stamp, ?string $release): void
    {
        if ($this->storagePath === null) {
            return;
        }

        try {
            if (! is_dir($this->storagePath) && ! @mkdir($this->storagePath, 0775, true) && ! is_dir($this->storagePath)) {
                return;
            }

            @file_put_contents($this->storagePath.'/release', json_encode(['stamp' => $stamp, 'release' => $release]), LOCK_EX);
        } catch (Throwable $e) {
            // The cache is an optimisation only.
        }
    }
}
