<?php

use NightGuardian\Laravel\Support\EnvFile;

function ngEnvPath(string $contents): string
{
    $path = sys_get_temp_dir().'/ng-env-'.bin2hex(random_bytes(5));
    file_put_contents($path, $contents);

    return $path;
}

it('replaces existing keys and leaves every other byte alone', function () {
    $original = "APP_NAME=Shop\n# NIGHTGUARDIAN_URL=commented\nNIGHTGUARDIAN_URL=https://old.example.com\nAPP_KEY=base64:abc==\nNIGHTGUARDIAN_TOKEN=ng_old\n\nOTHER=\"a b\"\n";
    $path = ngEnvPath($original);

    expect(EnvFile::set($path, ['NIGHTGUARDIAN_URL' => 'https://ng.example.com', 'NIGHTGUARDIAN_TOKEN' => 'ng_new']))->toBeTrue();

    expect(file_get_contents($path))->toBe("APP_NAME=Shop\n# NIGHTGUARDIAN_URL=commented\nNIGHTGUARDIAN_URL=https://ng.example.com\nAPP_KEY=base64:abc==\nNIGHTGUARDIAN_TOKEN=ng_new\n\nOTHER=\"a b\"\n");
});

it('appends missing keys at the end, adding the newline the file lacks', function (string $original, string $expected) {
    $path = ngEnvPath($original);

    EnvFile::set($path, ['NIGHTGUARDIAN_URL' => 'https://ng.example.com', 'NIGHTGUARDIAN_TOKEN' => 'ng_new']);

    expect(file_get_contents($path))->toBe($expected);
})->with([
    'with trailing newline' => ["APP_ENV=local\n", "APP_ENV=local\nNIGHTGUARDIAN_URL=https://ng.example.com\nNIGHTGUARDIAN_TOKEN=ng_new\n"],
    'without trailing newline' => ['APP_ENV=local', "APP_ENV=local\nNIGHTGUARDIAN_URL=https://ng.example.com\nNIGHTGUARDIAN_TOKEN=ng_new\n"],
    'windows line endings' => ["APP_ENV=local\r\n", "APP_ENV=local\r\nNIGHTGUARDIAN_URL=https://ng.example.com\r\nNIGHTGUARDIAN_TOKEN=ng_new\r\n"],
    'empty file' => ['', "NIGHTGUARDIAN_URL=https://ng.example.com\nNIGHTGUARDIAN_TOKEN=ng_new\n"],
]);

it('keeps windows line endings when replacing', function () {
    $path = ngEnvPath("A=1\r\nNIGHTGUARDIAN_TOKEN=ng_old\r\nB=2\r\n");

    EnvFile::set($path, ['NIGHTGUARDIAN_TOKEN' => 'ng_new']);

    expect(file_get_contents($path))->toBe("A=1\r\nNIGHTGUARDIAN_TOKEN=ng_new\r\nB=2\r\n");
});

it('does not touch the file when nothing changes', function () {
    $path = ngEnvPath("NIGHTGUARDIAN_URL=https://ng.example.com\n");
    $mtime = filemtime($path);
    clearstatcache();

    expect(EnvFile::set($path, ['NIGHTGUARDIAN_URL' => 'https://ng.example.com']))->toBeFalse()
        ->and(filemtime($path))->toBe($mtime);
});

it('rewrites the same file instead of replacing it', function () {
    $path = ngEnvPath("A=1\n");
    $permissions = fileperms($path);

    EnvFile::set($path, ['A' => '2']);
    clearstatcache();

    expect(fileperms($path))->toBe($permissions)->and(file_get_contents($path))->toBe("A=2\n");
});

it('quotes only the values that need it', function (string $value, string $line) {
    $path = ngEnvPath('');

    EnvFile::set($path, ['K' => $value]);

    expect(file_get_contents($path))->toBe($line."\n");
})->with([
    'plain' => ['ng_AbC123', 'K=ng_AbC123'],
    'url' => ['https://ng.example.com:8443', 'K=https://ng.example.com:8443'],
    'space' => ['a b', 'K="a b"'],
    'hash' => ['a#b', 'K="a#b"'],
    'quote' => ['a"b', 'K="a\"b"'],
]);

it('fails clearly when the file is missing', function () {
    expect(function () {
        EnvFile::set(sys_get_temp_dir().'/ng-missing-'.bin2hex(random_bytes(4)), ['A' => '1']);
    })->toThrow(RuntimeException::class, 'does not exist');
});
