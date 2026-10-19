<?php
/*
 * web_socket.php
 *
 * HTTP API для чтения и записи регистров Modbus-TCP через modbus.py.
 *
 * Параметры запроса: ip, port, fcode, reg_addr, r_count; data для записи,
 * type для чтения. Ответы возвращаются в формате JSON.
 *
 * @author  Pavel Dik: paveldik@gmail.com
 * @version 1.0
 */

define('PYTHON_BIN', '/usr/share/modbus-api/venv/bin/python');
define('MODBUS_SCRIPT', __DIR__ . '/modbus.py');
define('MODBUS_PROCESS_TIMEOUT', 5);

function sendJson(array $result, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

function SendError($Errnum, $ErrText)
{
    sendJson(['Error' => (string)$Errnum, 'ErrorText' => $ErrText], 400);
    exit();
}

function getvar($name)
{
    if (isset($_POST[$name])) {
        return $_POST[$name];
    }
    if (isset($_GET[$name])) {
        return $_GET[$name];
    }
    return null;
}

function parseInteger($value, $name, $minimum, $maximum)
{
    if (!is_scalar($value) || !preg_match('/^[0-9]+$/D', (string)$value)) {
        SendError(13, 'Параметр ' . $name . ' должен быть целым неотрицательным числом');
    }

    $number = (int)$value;
    if ($number < $minimum || $number > $maximum) {
        SendError(14, 'Параметр ' . $name . ' вне допустимого диапазона');
    }
    return $number;
}

/*
 * Запускает modbus.py с ограничением времени выполнения.
 *
 * @param array $args Аргументы командной строки для modbus.py.
 * @return string|null Вывод Python или null при ошибке запуска/тайм-ауте.
 */
function runModbus(array $args): ?string
{
    $cmd = 'exec ' . escapeshellarg(PYTHON_BIN) . ' ' . escapeshellarg(MODBUS_SCRIPT);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string)$arg);
    }
    $cmd .= ' 2>&1';

    $process = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        return null;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $errorOutput = '';
    $deadline = microtime(true) + MODBUS_PROCESS_TIMEOUT;
    $timedOut = false;

    do {
        $chunk = stream_get_contents($pipes[1]);
        if ($chunk !== false) {
            $output .= $chunk;
        }
        $errorChunk = stream_get_contents($pipes[2]);
        if ($errorChunk !== false) {
            $errorOutput .= $errorChunk;
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            $output .= stream_get_contents($pipes[1]);
            $errorOutput .= stream_get_contents($pipes[2]);
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(10000);
    } while (true);

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    if ($errorOutput !== '') {
        error_log('Вывод stderr modbus.py: ' . trim($errorOutput));
    }
    return $timedOut ? null : trim($output);
}

function runRegisters($action, $ip, $port, $regAddr, $count, $value)
{
    $output = runModbus([$action, $ip, $port, $regAddr, $count, $value]);
    if ($output === null) {
        error_log('Не удалось запустить modbus.py или превышено время ожидания');
        SendError($action === 3 ? 203 : 216, 'Модуль Modbus не ответил за отведённое время');
    }

    $result = json_decode($output, true);
    if (!is_array($result) || !isset($result['Error'], $result['ErrorText'])) {
        error_log('Некорректный ответ modbus.py: ' . $output);
        SendError($action === 3 ? 203 : 216, 'Не удалось получить корректный ответ модуля Modbus');
    }
    if ((string)$result['Error'] !== '0') {
        error_log('Ошибка modbus.py: ' . $output);
        SendError(
            $action === 3 ? 103 : 116,
            'Модуль ' . ($action === 3 ? 'чтения' : 'записи') .
            ' Modbus вернул ошибку: ' . $result['ErrorText']
        );
    }
    sendJson($result);
}

$fcode = getvar('fcode');
$ip = getvar('ip');
$port = getvar('port');
$regAddr = getvar('reg_addr');
$count = getvar('r_count');
$data = getvar('data');
$type = getvar('type');

if ($fcode === null) {
    SendError(1, 'Не указана Modbus-команда (параметр fcode=3 или fcode=16)');
}
if (!is_scalar($fcode) || !in_array((string)$fcode, ['3', '16'], true)) {
    SendError(2, 'Указана не поддерживаемая Modbus-команда (допустимы fcode=3 и fcode=16)');
}
if (!is_scalar($ip) || trim((string)$ip) === '' || preg_match('/[\x00-\x20\x7f]/', (string)$ip)) {
    SendError(3, 'Не указан корректный IP-адрес или hostname Modbus-TCP устройства');
}
if ($port === null) {
    SendError(6, 'Не указан номер tcp-порта Modbus-TCP устройства (параметр port)');
}
if ($regAddr === null) {
    SendError(7, 'Не указан номер Modbus-регистра устройства (параметр reg_addr)');
}
if ($count === null) {
    SendError(9, 'Не указано количество обрабатываемых Modbus-регистров (параметр r_count)');
}
if ((int)$fcode === 16 && ($data === null || !is_scalar($data) || trim((string)$data) === '')) {
    SendError(12, 'Отсутствует список данных, записываемых в Modbus-регистр (параметр data)');
}

$port = parseInteger($port, 'port', 1, 65535);
$regAddr = parseInteger($regAddr, 'reg_addr', 0, 65535);
$maxCount = (int)$fcode === 3 ? 125 : 123;
$count = parseInteger($count, 'r_count', 1, $maxCount);
if ($regAddr + $count > 65536) {
    SendError(14, 'Диапазон регистров выходит за допустимый адрес 65535');
}

$ip = trim((string)$ip);
if ($type !== null && !is_scalar($type)) {
    SendError(15, 'Недопустимое значение параметра type');
}
$type = $type === null ? 'none' : strtolower(trim((string)$type));
if (!in_array($type, ['none', 'float', 'int', 'uint', 'utime', 'coil'], true)) {
    SendError(15, 'Недопустимое значение параметра type');
}

if ((int)$fcode === 16) {
    $values = array_map('trim', explode(',', (string)$data));
    if (count($values) !== $count) {
        SendError(10, 'Количество значений data не совпадает с количеством записываемых регистров');
    }
    foreach ($values as $value) {
        if (!preg_match('/^[0-9]+$/D', $value) || (int)$value > 65535) {
            SendError(15, 'Каждое значение data должно быть целым числом от 0 до 65535');
        }
    }
    runRegisters(16, $ip, $port, $regAddr, $count, implode(',', $values));
}

runRegisters(3, $ip, $port, $regAddr, $count, $type);
