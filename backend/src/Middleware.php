<?php
declare(strict_types=1);

/** Cross-cutting HTTP middleware/helpers for the EventEase API. */
final class ApiMiddleware {
    public static function logRequest(string $method, string $path, int $status, float $elapsedMs): void {
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $line = json_encode(['time'=>date(DATE_ATOM),'method'=>$method,'path'=>$path,'status'=>$status,'duration_ms'=>round($elapsedMs,2),'ip'=>$_SERVER['REMOTE_ADDR'] ?? 'unknown'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        if (is_dir($dir) && is_writable($dir)) @file_put_contents($dir . '/api.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function allowLoginAttempt(string $identity): bool {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eventease-rate-limit';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return true;
        $file = $dir . DIRECTORY_SEPARATOR . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . strtolower($identity)) . '.json';
        $fp = @fopen($file, 'c+');
        if (!$fp) return true;
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $state = json_decode($raw ?: '{}', true) ?: ['start'=>time(),'count'=>0];
        if (time() - (int)($state['start'] ?? 0) >= 300) $state = ['start'=>time(),'count'=>0];
        $allowed = (int)$state['count'] < 10;
        if ($allowed) $state['count']++;
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($state)); fflush($fp);
        flock($fp, LOCK_UN); fclose($fp);
        return $allowed;
    }

    public static function createJwt(array $claims): string {
        $header = ['alg'=>'HS256','typ'=>'JWT'];
        $encode = static fn(array $v): string => rtrim(strtr(base64_encode(json_encode($v, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $input = $encode($header) . '.' . $encode($claims);
        return $input . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $input, JWT_SECRET, true)), '+/', '-_'), '=');
    }

    public static function verifyJwt(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        [$head,$payload,$signature] = $parts;
        $header = json_decode(self::b64decode($head), true);
        $claims = json_decode(self::b64decode($payload), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256' || !is_array($claims)) return null;
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $head.'.'.$payload, JWT_SECRET, true)), '+/', '-_'), '=');
        if (!hash_equals($expected, $signature) || (int)($claims['exp'] ?? 0) <= time() || empty($claims['sub']) || empty($claims['jti'])) return null;
        return $claims;
    }

    private static function b64decode(string $value): string {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return $decoded === false ? '' : $decoded;
    }

    public static function requireApiKey(): void {
        $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (!is_string($provided) || !hash_equals(API_KEY, $provided)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success'=>false,'error'=>['code'=>'API_KEY_REQUIRED','message'=>'A valid X-API-Key header is required.']]);
            exit;
        }
    }
}
