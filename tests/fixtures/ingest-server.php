<?php

/*
 * Router for `php -S`: a fake collector whose behaviour is chosen by the bearer token.
 *
 *   ok → 202 with one "accepted" result per event      bad → 401        forbidden → 403
 *   limit → 429 + Retry-After: 7                        boom → 500
 *   big → 413                                           malformed → 400
 *   slow → answers after 3 seconds                      html → 200 text/html
 *   redirect → 302                                      huge → 202 with a 3 MB body
 *
 * Every request is appended (one JSON line) to the file named by NG_CAPTURE_FILE.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path !== '/api/v1/ingest') {
    http_response_code(404);
    echo json_encode(['message' => 'not found']);

    return;
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$headers = array_change_key_case($headers, CASE_LOWER);
$token = preg_replace('/^Bearer\s+/i', '', $headers['authorization'] ?? '');
$raw = file_get_contents('php://input');
$encoding = $headers['content-encoding'] ?? '';
$body = $encoding === 'gzip' ? gzdecode($raw) : $raw;

if (getenv('NG_CAPTURE_FILE')) {
    file_put_contents(getenv('NG_CAPTURE_FILE'), json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'headers' => $headers,
        'body' => json_decode((string) $body, true),
        'compressed_bytes' => strlen($raw),
    ])."\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');

switch ($token) {
    case 'bad':
        http_response_code(401);
        echo json_encode(['message' => 'Invalid token.']);
        break;
    case 'forbidden':
        http_response_code(403);
        echo json_encode(['message' => 'Project is archived.']);
        break;
    case 'limit':
        http_response_code(429);
        header('Retry-After: 7');
        echo json_encode(['message' => 'Too many events.']);
        break;
    case 'boom':
        http_response_code(500);
        echo 'Server Error';
        break;
    case 'big':
        http_response_code(413);
        echo json_encode(['message' => 'Payload too large.']);
        break;
    case 'malformed':
        http_response_code(400);
        echo json_encode(['message' => 'Malformed body.']);
        break;
    case 'slow':
        sleep(3);
        http_response_code(202);
        echo json_encode(['accepted' => 0, 'rejected' => 0, 'results' => []]);
        break;
    case 'html':
        header('Content-Type: text/html');
        echo '<html>maintenance</html>';
        break;
    case 'redirect':
        http_response_code(302);
        header('Location: http://127.0.0.1:1/elsewhere');
        break;
    case 'huge':
        http_response_code(202);
        echo str_repeat('x', 3 * 1024 * 1024);
        break;
    default:
        $events = json_decode((string) $body, true)['events'] ?? [];
        $results = [];

        foreach ($events as $event) {
            $results[] = ['id' => $event['id'] ?? null, 'status' => 'accepted', 'issue' => '01JREALSERVER'];
        }

        http_response_code(202);
        echo json_encode(['accepted' => count($results), 'rejected' => 0, 'results' => $results]);
}
