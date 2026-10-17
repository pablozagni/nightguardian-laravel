<?php

use NightGuardian\Laravel\Privacy\Scrubber;

it('filters values of denylisted keys case-insensitively, by substring', function (string $key) {
    $result = (new Scrubber)->scrub([$key => 'super-secret-value', 'safe' => 'visible']);

    expect($result[$key])->toBe('[Filtered]')->and($result['safe'])->toBe('visible');
})->with([
    'password', 'PASSWORD', 'user_password_confirmation', 'passwd', 'pwd', 'client_secret', 'api_token',
    'api_key', 'apikey', 'access_key', 'private_key', 'Authorization', 'auth', 'Cookie', 'session_id',
    'csrf_token', 'xsrf', 'credit_card', 'card_number', 'cvv', 'iban',
]);

it('filters nested arrays and lists', function () {
    $result = (new Scrubber)->scrub([
        'context' => ['user' => ['password' => 'x', 'name' => 'Ada'], 'items' => [['token' => 'y', 'sku' => 'A1']]],
    ]);

    expect($result['context']['user'])->toBe(['password' => '[Filtered]', 'name' => 'Ada'])
        ->and($result['context']['items'][0])->toBe(['token' => '[Filtered]', 'sku' => 'A1']);
});

it('filters Luhn-valid card numbers inside strings', function (string $input) {
    expect((new Scrubber)->scrubString("Charge failed for card {$input} today"))
        ->toBe('Charge failed for card [Filtered] today');
})->with([
    'visa plain' => '4111111111111111',
    'visa spaced' => '4111 1111 1111 1111',
    'visa dashed' => '4111-1111-1111-1111',
    'amex' => '378282246310005',
]);

it('keeps digit sequences that are not card numbers', function () {
    $scrubber = new Scrubber;

    expect($scrubber->scrubString('Order 4111111111111112 failed'))->toBe('Order 4111111111111112 failed')
        ->and($scrubber->scrubString('Invoice 12345'))->toBe('Invoice 12345')
        ->and($scrubber->scrubString('Total 1234567890.50'))->toBe('Total 1234567890.50');
});

it('filters bearer tokens, JWTs and private key blocks', function () {
    $scrubber = new Scrubber;
    $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTYifQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';

    expect($scrubber->scrubString('Header Authorization: Bearer abc.DEF-123_xyz== sent'))->toBe('Header Authorization: Bearer [Filtered] sent')
        ->and($scrubber->scrubString("jwt={$jwt}"))->toBe('jwt=[Filtered]')
        ->and($scrubber->scrubString("key:\n-----BEGIN RSA PRIVATE KEY-----\nMIIEow\n-----END RSA PRIVATE KEY-----\nend"))->toBe("key:\n[Filtered]\nend")
        ->and($scrubber->scrubString("cut off:\n-----BEGIN PRIVATE KEY-----\nMIIEow"))->toBe("cut off:\n[Filtered]");
});

it('scrubs strings found inside arrays', function () {
    $result = (new Scrubber)->scrub(['message' => 'Bearer abcdef123', 'nested' => ['note' => 'card 4111111111111111']]);

    expect($result['message'])->toBe('Bearer [Filtered]')->and($result['nested']['note'])->toBe('card [Filtered]');
});

it('supports extra denylist keys', function () {
    $result = (new Scrubber(['cuit', ' CBU ', '']))->scrub(['cuit' => '20-12345678-9', 'cbu' => '000', 'name' => 'Ada']);

    expect($result)->toBe(['cuit' => '[Filtered]', 'cbu' => '[Filtered]', 'name' => 'Ada']);
});

it('leaves non-string scalars untouched', function () {
    expect((new Scrubber)->scrub(['count' => 3, 'ok' => true, 'nothing' => null, 'ratio' => 1.5]))
        ->toBe(['count' => 3, 'ok' => true, 'nothing' => null, 'ratio' => 1.5]);
});

it('does not choke on invalid UTF-8', function () {
    expect((new Scrubber)->scrubString("bad \xB1\x31 bytes"))->toBe("bad \xB1\x31 bytes");
});

it('scrubs URLs: query parameters, credentials and fragments', function (string $input, string $expected) {
    expect((new Scrubber)->scrubUrl($input))->toBe($expected);
})->with([
    'plain' => ['https://app.test/invoices/12', 'https://app.test/invoices/12'],
    'harmless query' => ['https://app.test/invoices?page=2&sort=asc', 'https://app.test/invoices?page=2&sort=asc'],
    'token param' => ['https://app.test/cb?token=abc123&page=2', 'https://app.test/cb?token=[Filtered]&page=2'],
    'case and encoding' => ['https://app.test/cb?API_Key=abc&x=1', 'https://app.test/cb?API_Key=[Filtered]&x=1'],
    'password param' => ['https://app.test/login?user=ada&password=hunter2', 'https://app.test/login?user=ada&password=[Filtered]'],
    'credentials' => ['https://ada:hunter2@app.test/x', 'https://app.test/x'],
    'fragment' => ['https://app.test/x?a=1#access_token=abc', 'https://app.test/x?a=1'],
    'card in value' => ['https://app.test/pay?card=4111111111111111', 'https://app.test/pay?card=[Filtered]'],
    'flag without value' => ['https://app.test/x?debug&page=2', 'https://app.test/x?debug&page=2'],
]);

it('keeps only allowlisted headers and never cookies or authorization', function () {
    $headers = [
        'User-Agent' => ['Mozilla/5.0'],
        'Accept' => ['text/html', 'application/json'],
        'Cookie' => ['laravel_session=abc'],
        'Authorization' => ['Bearer abc'],
        'X-Custom' => ['nope'],
        'Referer' => ['https://app.test/cb?token=zzz'],
    ];

    $result = (new Scrubber)->scrubHeaders($headers, ['user-agent', 'accept', 'cookie', 'authorization', 'referer']);

    expect($result)->toBe([
        'user-agent' => 'Mozilla/5.0',
        'accept' => 'text/html, application/json',
        'referer' => 'https://app.test/cb?token=[Filtered]',
    ]);
});

it('scrubs allowlisted header values', function () {
    $result = (new Scrubber)->scrubHeaders(['X-Note' => 'Bearer abc123def'], ['x-note']);

    expect($result)->toBe(['x-note' => 'Bearer [Filtered]']);
});
