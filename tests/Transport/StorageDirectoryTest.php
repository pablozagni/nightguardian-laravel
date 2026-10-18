<?php

use NightGuardian\Laravel\Support\StorageDirectory;
use NightGuardian\Laravel\Tests\Fakes\FakeClock;
use NightGuardian\Laravel\Transport\Spool;
use NightGuardian\Laravel\Transport\Stats;

function ngScratch(): string
{
    $dir = sys_get_temp_dir().'/ng-gitignore-'.bin2hex(random_bytes(5));
    mkdir($dir, 0775, true);

    return $dir;
}

it('creates storage/nightguardian with a .gitignore that ignores everything but itself', function () {
    $storage = ngScratch();

    expect(StorageDirectory::ensure($storage.'/nightguardian/spool'))->toBeTrue()
        ->and(is_dir($storage.'/nightguardian/spool'))->toBeTrue()
        ->and(file_get_contents($storage.'/nightguardian/.gitignore'))->toBe("*\n!.gitignore\n");
});

it('writes the .gitignore the first time the spool or the statistics create the folder', function () {
    $storage = ngScratch();
    (new Spool($storage.'/nightguardian/spool', 1048576, 7, new FakeClock))->write([['id' => 'a', 'type' => 'log']]);

    expect(file_get_contents($storage.'/nightguardian/.gitignore'))->toBe("*\n!.gitignore\n");

    $other = ngScratch();
    $stats = new Stats($other.'/nightguardian/stats.json');
    $stats->increment('sent');
    $stats->save();

    expect(file_exists($other.'/nightguardian/.gitignore'))->toBeTrue();
});

it('leaves an existing folder and its .gitignore alone', function () {
    $storage = ngScratch();
    mkdir($storage.'/nightguardian', 0775, true);

    expect(StorageDirectory::ensure($storage.'/nightguardian'))->toBeTrue()
        ->and(file_exists($storage.'/nightguardian/.gitignore'))->toBeFalse();

    $other = ngScratch();
    StorageDirectory::ensure($other.'/nightguardian');
    file_put_contents($other.'/nightguardian/.gitignore', 'custom');
    StorageDirectory::ensure($other.'/nightguardian');

    expect(file_get_contents($other.'/nightguardian/.gitignore'))->toBe('custom');
});

it('reports unwritable storage without throwing', function () {
    $file = ngScratch().'/a-file';
    file_put_contents($file, 'x');

    expect(StorageDirectory::ensure($file.'/nightguardian'))->toBeFalse();
});
