<?php
// 随身听后端启动引导：自动加载、配置、错误处理、JSON 响应

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

// ---------- 配置 ----------
if (!is_file(APP_ROOT . '/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 500, 'msg' => '服务端未配置：请复制 config.example.php 为 config.php', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}
$GLOBALS['config'] = require APP_ROOT . '/config.php';

function config(string $key, $default = null)
{
    $parts = explode('.', $key);
    $val = $GLOBALS['config'];
    foreach ($parts as $p) {
        if (!is_array($val) || !array_key_exists($p, $val)) {
            return $default;
        }
        $val = $val[$p];
    }
    return $val;
}

// ---------- 自动加载 ----------
spl_autoload_register(function (string $class) {
    $base = APP_ROOT . '/app/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($base)) {
        require $base;
        return;
    }
    $ctrl = APP_ROOT . '/app/controllers/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($ctrl)) {
        require $ctrl;
    }
});

// ---------- 错误处理：任何异常都返回 JSON ----------
error_reporting(E_ALL);
ini_set('display_errors', '0');

set_exception_handler(function (Throwable $e) {
    error_log('[sst-music] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 500, 'msg' => '服务器内部错误', 'data' => null], JSON_UNESCAPED_UNICODE);
});

set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

// ---------- 基础工具 ----------

/**
 * 输出 JSON 并结束
 */
function json_out($data = null, int $code = 0, string $msg = 'ok', int $httpStatus = 200): void
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 业务失败快捷方式
 */
function fail(int $code, string $msg, int $httpStatus = 400, $data = null): void
{
    json_out($data, $code, $msg, $httpStatus);
}

/**
 * 读取 JSON 或表单请求体
 */
function input(): array
{
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '[]', true);
        return is_array($data) ? $data : [];
    }
    return $_POST ?: [];
}

/**
 * 取字符串参数（首尾去空白），不存在返回默认值
 */
function str_param(array $src, string $key, string $default = ''): string
{
    $v = $src[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/**
 * 取整数参数
 */
function int_param(array $src, string $key, int $default = 0): int
{
    $v = $src[$key] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}

/**
 * 当前毫秒时间戳
 */
function now_ms(): int
{
    return (int)floor(microtime(true) * 1000);
}

/**
 * 当前秒级时间戳
 */
function now_s(): int
{
    return time();
}

/**
 * 可写运行时目录
 */
function runtime_dir(): string
{
    $dir = (string)config('runtime_dir', APP_ROOT . '/storage');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * CORS：允许任意来源（移动端 App 不校验，H5 调试需要）
 */
function send_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    } else {
        header('Access-Control-Allow-Origin: *');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Max-Age: 86400');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
