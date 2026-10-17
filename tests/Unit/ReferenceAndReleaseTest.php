<?php

use NightGuardian\Laravel\Event\Reference;
use NightGuardian\Laravel\Event\Release;

function ngTempDir(): string
{
    $dir = sys_get_temp_dir().'/ng-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    return $dir;
}

function ngRemove(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

/** A fake project with a .git directory on the given branch/commit. */
function ngFakeRepo(string $sha = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678', string $branch = 'main'): string
{
    $root = ngTempDir();
    mkdir($root.'/.git/refs/heads', 0777, true);
    file_put_contents($root.'/.git/HEAD', "ref: refs/heads/{$branch}\n");
    file_put_contents($root.'/.git/refs/heads/'.$branch, $sha."\n");

    return $root;
}

// --- Reference -------------------------------------------------------------------------------------

it('generates references in the NG-XXXX-XXXX Crockford format', function () {
    for ($i = 0; $i < 200; $i++) {
        $reference = Reference::generate();

        expect($reference)->toMatch('/^NG-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/')
            ->and(Reference::isValid($reference))->toBeTrue();
    }
});

it('never uses the ambiguous letters I, L, O or U', function () {
    $all = '';

    for ($i = 0; $i < 300; $i++) {
        $all .= substr(Reference::generate(), 3);
    }

    expect($all)->not->toMatch('/[ILOU]/');
});

it('generates different references', function () {
    $references = array_map(fn () => Reference::generate(), range(1, 100));

    expect(count(array_unique($references)))->toBeGreaterThan(95);
});

it('rejects malformed references', function (string $value) {
    expect(Reference::isValid($value))->toBeFalse();
})->with(['', 'NG-7F3K-92Q', 'ng-7F3K-92QD', 'NG-7F3K-92QI', 'NG-7F3K92QD', 'XX-7F3K-92QD', 'NG-7F3K-92QDX']);

// --- Release ---------------------------------------------------------------------------------------

it('prefers the configured release', function () {
    $root = ngFakeRepo();

    expect((new Release('v1.4.2', $root))->resolve())->toBe('v1.4.2');

    ngRemove($root);
});

it('truncates an overlong configured release to 128 characters', function () {
    expect(strlen((new Release(str_repeat('x', 300), sys_get_temp_dir()))->resolve()))->toBe(128);
});

it('reads the short SHA from .git/HEAD and the branch ref', function () {
    $root = ngFakeRepo();

    expect((new Release(null, $root))->resolve())->toBe('a1b2c3d');

    ngRemove($root);
});

it('reads a detached HEAD', function () {
    $root = ngTempDir();
    mkdir($root.'/.git', 0777, true);
    file_put_contents($root.'/.git/HEAD', "0123456789abcdef0123456789abcdef01234567\n");

    expect((new Release(null, $root))->resolve())->toBe('0123456');

    ngRemove($root);
});

it('falls back to packed-refs', function () {
    $root = ngTempDir();
    mkdir($root.'/.git', 0777, true);
    file_put_contents($root.'/.git/HEAD', "ref: refs/heads/main\n");
    file_put_contents($root.'/.git/packed-refs', "# pack-refs with: peeled fully-peeled sorted\nfedcba9876543210fedcba9876543210fedcba98 refs/heads/main\n");

    expect((new Release(null, $root))->resolve())->toBe('fedcba9');

    ngRemove($root);
});

it('follows a .git file that points to the real git directory (worktrees)', function () {
    $root = ngTempDir();
    $real = ngTempDir();
    file_put_contents($real.'/HEAD', "9999999999999999999999999999999999999999\n");
    file_put_contents($root.'/.git', 'gitdir: '.$real."\n");

    expect((new Release(null, $root))->resolve())->toBe('9999999');

    ngRemove($root);
    ngRemove($real);
});

it('returns null without a git repository, with an empty one, or with garbage', function () {
    $empty = ngTempDir();
    $garbage = ngTempDir();
    mkdir($garbage.'/.git', 0777, true);
    file_put_contents($garbage.'/.git/HEAD', 'not a sha');

    expect((new Release(null, $empty))->resolve())->toBeNull()
        ->and((new Release(null, $garbage))->resolve())->toBeNull()
        ->and((new Release(null, '/definitely/not/here'))->resolve())->toBeNull();

    ngRemove($empty);
    ngRemove($garbage);
});

it('caches the release in storage and invalidates it when the branch ref changes', function () {
    $root = ngFakeRepo('1111111111111111111111111111111111111111');
    $storage = ngTempDir();

    expect((new Release(null, $root, $storage))->resolve())->toBe('1111111');
    expect(file_exists($storage.'/release'))->toBeTrue();

    // The cache is used while nothing changes, even if the ref contents are rewritten with the same mtime.
    $ref = $root.'/.git/refs/heads/main';
    $mtime = filemtime($ref);
    file_put_contents($ref, "2222222222222222222222222222222222222222\n");
    touch($ref, $mtime);
    expect((new Release(null, $root, $storage))->resolve())->toBe('1111111');

    // A new commit/deploy changes the ref file's mtime: the cache is dropped.
    touch($ref, $mtime + 10);
    expect((new Release(null, $root, $storage))->resolve())->toBe('2222222');

    ngRemove($root);
    ngRemove($storage);
});

it('still resolves the release when the storage path cannot be written', function () {
    $root = ngFakeRepo();
    $file = tempnam(sys_get_temp_dir(), 'ng');

    // A path below a regular file can never be created.
    expect((new Release(null, $root, $file.'/nightguardian'))->resolve())->toBe('a1b2c3d');

    unlink($file);
    ngRemove($root);
});

it('resolves only once per instance', function () {
    $root = ngFakeRepo();
    $release = new Release(null, $root);

    expect($release->resolve())->toBe('a1b2c3d');

    file_put_contents($root.'/.git/refs/heads/main', "3333333333333333333333333333333333333333\n");

    expect($release->resolve())->toBe('a1b2c3d');

    ngRemove($root);
});
