# modbus-api

HTTP-обёртка над Modbus-TCP устройствами для взаимодействия с MODBUS-TCP
устройствами из браузера, а также для интеграции через web-api с 1С.

PHP-скрипт принимает HTTP-запрос, запускает Python-скрипт на базе
[`pymodbus`](https://github.com/pymodbus-dev/pymodbus), тот общается с
Modbus-TCP slave-устройством, а результат возвращается в виде JSON.
Поддерживается чтение группы holding-регистров (`fcode=3`) и запись
группы регистров (`fcode=16`), а также приведение прочитанных значений
к человекочитаемому виду (`float`, `int`, `uint`, `utime`, `coil`).

## Структура репозитория

```text
.
├── README.md
├── requirements.txt
└── html/
    ├── modbus.py        # Python-обёртка над pymodbus
    └── web_socket.php   # HTTP-API (точка входа)
```

## Требования

- Linux (проверено на Debian/Ubuntu)
- Python **3.8** (код совместим с 3.8–3.11; на 3.12+ возможны проблемы
  из-за устаревших зависимостей `pymodbus==2.5.3`)
- PHP **7.1+** (используется `?string` в сигнатуре функции и `JSON_UNESCAPED_UNICODE`)
- Веб-сервер (nginx/apache) с PHP-FPM или mod_php
- Доступ к Modbus-TCP slave-устройству по сети

## Установка

### 1. Клонирование репозитория

Разместите содержимое папки `html/` в document root веб-сервера, например:

```bash
sudo mkdir -p /var/www/modbus-api
sudo git clone <URL_репозитория> /tmp/modbus-api
sudo cp /tmp/modbus-api/html/* /var/www/modbus-api/
sudo cp /tmp/modbus-api/requirements.txt /var/www/modbus-api/
```

### 2. Виртуальное окружение Python (вне document root)

Виртуальное окружение намеренно размещается **вне** каталога, отдаваемого
веб-сервером, — чтобы его файлы нельзя было запросить по HTTP.

```bash
sudo python3 -m venv /usr/share/modbus-api/venv
sudo /usr/share/modbus-api/venv/bin/pip install --upgrade pip
sudo /usr/share/modbus-api/venv/bin/pip install -r /var/www/modbus-api/requirements.txt
```

Проверка:

```bash
/usr/share/modbus-api/venv/bin/python -c "import pymodbus; print(pymodbus.__version__)"
# ожидаемо: 2.5.3
```

### 3. Права доступа для веб-сервера

Пользователь веб-сервера (`www-data`, `apache`, `nginx` — зависит от дистрибутива)
должен иметь право читать и исполнять файлы venv и читать `modbus.py`:

```bash
# вариант через группу (аккуратнее):
sudo chgrp -R www-data /usr/share/modbus-api/venv
sudo chmod -R g+rX /usr/share/modbus-api/venv
sudo chmod o+rx /usr/share/modbus-api

# либо, если не смущает «широкий» доступ на чтение:
# sudo chmod -R o+rX /usr/share/modbus-api/venv
```

Проверка от имени веб-сервера:

```bash
sudo -u www-data /usr/share/modbus-api/venv/bin/python \
    /var/www/modbus-api/modbus.py 3 localhost 502 10304 2 none
# ожидаемо: строка JSON (даже если Error=2 — не смогли соединиться)
```

### 4. Настройка пути к Python в PHP

В `web_socket.php` в начале файла заданы две константы:

```php
define('PYTHON_BIN', '/usr/share/modbus-api/venv/bin/python');
define('MODBUS_SCRIPT', __DIR__ . '/modbus.py');
```

`PYTHON_BIN` **должен** быть абсолютным путём к Python из venv. Если вы
разместили venv в другом месте — измените эту строку. Не заменяйте её на
просто `python3`: у пользователя веб-сервера `PATH` может отличаться, и
скрипт подхватит системный Python без `pymodbus`.

`MODBUS_SCRIPT` через `__DIR__` даёт абсолютный путь к `modbus.py` рядом
с PHP-файлом, независимо от текущей рабочей директории процесса.

### 5. Проверка API

```bash
curl -s "http://<host>/modbus/web_socket.php?ip=localhost&port=502&fcode=3&reg_addr=10304&r_count=2&type=none"
```

Ожидаемо (если Modbus-slave недоступен) — JSON с ошибкой:

```json
{"Error":"103","ErrorText":"Модуль чтения Modbus вернул ошибку: Не удалось соединиться с Modbus-Slave"}
```

Если слейв доступен — `Error` будет `0`, в ответе появятся `Data` и,
для `type != none` при `r_count=2`, — `ConvData`.

## Использование

### Параметры запроса

| Параметр  | Обязателен        | Описание                                                                 |
|-----------|-------------------|--------------------------------------------------------------------------|
| `ip`      | да                | IP-адрес или hostname Modbus-TCP slave                                   |
| `port`    | да                | TCP-порт slave (обычно 502)                                              |
| `fcode`   | да                | `3` — чтение holding-регистров, `16` — запись holding-регистров          |
| `reg_addr`| да                | Адрес первого регистра                                                   |
| `r_count` | да                | Количество регистров                                                     |
| `data`    | для `fcode=16`    | Список 16-битных значений `0..65535` через запятую                       |
| `type`    | нет (по умолч. `none`) | Тип приведения прочитанного значения: `none`, `float`, `int`, `uint`, `utime`, `coil` |

Параметры принимаются и через `GET`, и через `POST`.
Порт должен быть в диапазоне `1..65535`, адрес регистра — `0..65535`.
Чтение поддерживает от 1 до 125 регистров, запись — от 1 до 123; запрос
не может выходить за адрес `65535`. Записываемые значения должны быть
целыми числами `0..65535`.

### Чтение (`fcode=3`)

```bash
# 2 регистра, без приведения
curl -s "http://<host>/modbus/web_socket.php?ip=10.0.6.25&port=502&fcode=3&reg_addr=10304&r_count=2&type=none"

# 2 регистра как float (порядок байт Big-endian, порядок слов Little-endian)
curl -s "http://<host>/modbus/web_socket.php?ip=10.0.6.25&port=502&fcode=3&reg_addr=10304&r_count=2&type=float"

# 2 регистра как unix-time (с поправкой +4 часа от UTC)
curl -s "http://<host>/modbus/web_socket.php?ip=10.0.6.25&port=502&fcode=3&reg_addr=10304&r_count=2&type=utime"

# 2 регистра как набор битов (coil)
curl -s "http://<host>/modbus/web_socket.php?ip=10.0.6.25&port=502&fcode=3&reg_addr=10304&r_count=2&type=coil"
```

Пример успешного ответа:

```json
{
  "Error": "0",
  "ErrorText": "",
  "Data": [12345, 6789],
  "DataType": "float",
  "ConvData": 123.45
}
```

Для `type=utime` дополнительно возвращаются поля `Time` и `Time1c`
(в форматах `YYYY-MM-DD HH:MM:SS` и `YYYYMMDDHHMMSS0000` соответственно).

### Запись (`fcode=16`)

```bash
curl -s "http://<host>/modbus/web_socket.php?ip=10.0.6.25&port=502&fcode=16&reg_addr=1000&r_count=2&data=1234,3241"
```

Ожидаемый ответ:

```json
{"Error":"0","ErrorText":""}
```

> ⚠️ Запись выполняется в реальные регистры устройства. Убедитесь, что
> `reg_addr` указывает на безопасный для записи регистр. Не используйте
> для тестов регистры управления оборудованием.

## Коды ошибок API

| Код | Что означает                                                          |
|-----|-----------------------------------------------------------------------|
| 1   | Не указан `fcode`                                                     |
| 2   | Неподдерживаемый `fcode` (допустимы только 3 и 16)                    |
| 3   | Не указан `ip`                                                        |
| 6   | Не указан `port`                                                      |
| 7   | Не указан `reg_addr`                                                  |
| 9   | Не указан `r_count`                                                   |
| 10  | Количество значений в `data` не совпадает с `r_count`                 |
| 12  | Для `fcode=16` не передан `data`                                      |
| 13  | Параметр должен быть целым неотрицательным числом                     |
| 14  | Числовой параметр или диапазон регистров вне допустимых границ        |
| 15  | Некорректный `type` или значение `data`                               |
| 103 | `modbus.py` вернул ошибку при чтении (например, нет связи со slave)   |
| 116 | `modbus.py` вернул ошибку при записи                                  |
| 203 | Не удалось получить/распарсить ответ `modbus.py` при чтении           |
| 216 | Не удалось получить/распарсить ответ `modbus.py` при записи           |

При ошибках запуска или некорректном ответе Python API возвращает
обобщённое сообщение, а диагностическая информация записывается в журнал
ошибок PHP. Ожидание ответа Python ограничено пятью секундами; время
сетевого обмена с устройством ограничено настройкой клиента Modbus.

## Как это работает

```text
1С / curl
   │
   ▼
web_socket.php  ──(shell_exec, escapeshellarg)──►  venv/bin/python modbus.py <args>
   ▲                                                        │
   │                                                        ▼
   └────────────── JSON ◄────────────────────────────  pymodbus (TCP)
                                                            │
                                                            ▼
                                                     Modbus-TCP slave
```

- `web_socket.php` проверяет параметры и диапазоны, запускает Python с
  безопасно экранированными аргументами и ограничением времени ожидания.
- `modbus.py` читает аргументы, выполняет операцию через `pymodbus`,
  при необходимости декодирует 32-битные значения и возвращает JSON.
- PHP парсит JSON и отдаёт его клиенту как есть (или оборачивает в
  ошибку с кодом из таблицы выше).

## Обновление окружения

При изменении зависимостей:

```bash
sudo /usr/share/modbus-api/venv/bin/pip install -r /var/www/modbus-api/requirements.txt
sudo /usr/share/modbus-api/venv/bin/pip freeze > /var/www/modbus-api/requirements.txt
```

Не коммитьте в репозиторий папку venv — только `requirements.txt`.
В `.gitignore` должны быть:

```text
env/
venv/
__pycache__/
*.pyc
```

## Ограничение сетевого доступа

Размещайте API только в доверенной локальной сети и ограничьте доступ к
нему средствами веб-сервера или сетевого экрана. Запись изменяет реальные
регистры устройства; для доступа из недоверенных сетей сначала добавьте
аутентификацию и HTTPS.

## Известные ограничения

- `pymodbus==2.5.3` требует Python ≤ 3.11. На Python 3.12+ установка
  может падать из-за устаревших зависимостей.
- Приведение `type=float/int/uint/utime/coil` работает только при
  `r_count=2` (одно 32-битное значение = два 16-битных регистра).
- Порядок байт/слов жёстко задан в `modbus.py`:
  `byteorder=Endian.Big`, `wordorder=Endian.Little`. Если ваше устройство
  отдаёт данные в другом порядке — правьте соответствующие строки.
- Для `type=coil` порядок битов «склеивается» вручную
  (`tank_cur_bits1 + tank_cur_bits0 + tank_cur_bits3 + tank_cur_bits2`).
  Это специфично для конкретного устройства; при необходимости
  скорректируйте под свою битовую раскладку.
- `utime` жёстко прибавляет `4*3600` секунд (UTC+4). Если ваш регион
  другой — поправьте в `modbus.py`.

## Лицензия

Если не указано иное — уточните лицензию у владельца репозитория.
