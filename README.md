# modbus-api

[Русская версия](README.ru.md)

An HTTP wrapper for Modbus-TCP devices. It enables communication with
Modbus-TCP devices from a browser and integration with 1C through a web API.

The PHP script accepts an HTTP request and runs a Python script based on
[`pymodbus`](https://github.com/pymodbus-dev/pymodbus). The Python script
communicates with a Modbus-TCP slave and returns the result as JSON. The API
supports reading a group of holding registers (`fcode=3`) and writing a group
of registers (`fcode=16`), as well as converting read values to human-readable
types (`float`, `int`, `uint`, `utime`, `coil`).

## Repository structure

```text
.
├── README.md
├── README.ru.md
├── requirements.txt
└── html/
    ├── modbus.py        # Python wrapper for pymodbus
    └── web_socket.php   # HTTP API entry point
```

## Requirements

- Linux (tested on Debian/Ubuntu)
- Python **3.8** (the code is compatible with 3.8–3.11; Python 3.12+ may have
  issues due to the outdated `pymodbus==2.5.3` dependencies)
- PHP **7.1+** (`?string` in function signatures and `JSON_UNESCAPED_UNICODE`
  are used)
- A web server (nginx/apache) with PHP-FPM or mod_php
- Network access to a Modbus-TCP slave device

## Installation

### 1. Clone the repository

Place the contents of the `html/` directory in the web server's document root,
for example:

```bash
sudo mkdir -p /var/www/modbus-api
sudo git clone <REPOSITORY_URL> /tmp/modbus-api
sudo cp /tmp/modbus-api/html/* /var/www/modbus-api/
sudo cp /tmp/modbus-api/requirements.txt /var/www/modbus-api/
```

### 2. Python virtual environment (outside the document root)

The virtual environment is intentionally located **outside** the directory
served by the web server so its files cannot be requested over HTTP.

```bash
sudo python3 -m venv /usr/share/modbus-api/venv
sudo /usr/share/modbus-api/venv/bin/pip install --upgrade pip
sudo /usr/share/modbus-api/venv/bin/pip install -r /var/www/modbus-api/requirements.txt
```

Verify the installation:

```bash
/usr/share/modbus-api/venv/bin/python -c "import pymodbus; print(pymodbus.__version__)"
# expected: 2.5.3
```

### 3. Web server permissions

The web server user (`www-data`, `apache`, or `nginx`, depending on the
distribution) must be able to read and execute the virtual environment files
and read `modbus.py`:

```bash
# Group-based access (recommended):
sudo chgrp -R www-data /usr/share/modbus-api/venv
sudo chmod -R g+rX /usr/share/modbus-api/venv
sudo chmod o+rx /usr/share/modbus-api

# Alternatively, if broad read access is acceptable:
# sudo chmod -R o+rX /usr/share/modbus-api/venv
```

Test as the web server user:

```bash
sudo -u www-data /usr/share/modbus-api/venv/bin/python \
    /var/www/modbus-api/modbus.py 3 localhost 502 10304 2 none
# expected: a JSON string (even if Error=2 because the connection failed)
```

### 4. Configure the Python path in PHP

Two constants are defined near the beginning of `web_socket.php`:

```php
define('PYTHON_BIN', '/usr/share/modbus-api/venv/bin/python');
define('MODBUS_SCRIPT', __DIR__ . '/modbus.py');
```

`PYTHON_BIN` **must** be the absolute path to the Python interpreter in the
virtual environment. If the virtual environment is located elsewhere, update
this line. Do not replace it with just `python3`: the web server user's `PATH`
may be different, causing the script to use the system Python without
`pymodbus`.

`MODBUS_SCRIPT` uses `__DIR__` to provide the absolute path to `modbus.py`
alongside the PHP file, regardless of the process's current working directory.

### 5. Test the API

```bash
curl -s "http://<host>/modbus/web_socket.php?ip=localhost&port=502&fcode=3&reg_addr=10304&r_count=2&type=none"
```

If the Modbus slave is unavailable, the expected response is a JSON error:

```json
{"Error":"103","ErrorText":"Модуль чтения Modbus вернул ошибку: Не удалось соединиться с Modbus-Slave"}
```

If the slave is available, `Error` will be `0`, and the response will include
`Data` and, for `type != none` with `r_count=2`, `ConvData`.

## Usage

### Request parameters

| Parameter | Required | Description |
|-----------|----------|-------------|
| `ip` | yes | IP address or hostname of the Modbus-TCP slave |
| `port` | yes | Slave TCP port (usually 502) |
| `fcode` | yes | `3` — read holding registers; `16` — write holding registers |
| `reg_addr` | yes | Address of the first register |
| `r_count` | yes | Number of registers |
| `data` | for `fcode=16` | Comma-separated list of 16-bit values (`0..65535`) |
| `type` | no (default: `none`) | Read-value conversion type: `none`, `float`, `int`, `uint`, `utime`, `coil` |

Parameters can be passed via either `GET` or `POST`.
The port must be in the range `1..65535`, and the register address in
`0..65535`. Reading supports 1 to 125 registers; writing supports 1 to 123.
A request must not exceed address `65535`. Values to be written must be
integers in the range `0..65535`.

### Read (`fcode=3`)

```bash
# 2 registers, without conversion
curl -s "http://<host>/modbus/web_socket.php?ip=10.0.6.25&port=502&fcode=3&reg_addr=10304&r_count=2&type=none"

# 2 registers as float (big-endian byte order, little-endian word order)
curl -s "http://<host>/modbus/web_socket.php?ip=10.0.6.25&port=502&fcode=3&reg_addr=10304&r_count=2&type=float"

# 2 registers as Unix time (with a +4-hour offset from UTC)
curl -s "http://<host>/modbus/web_socket.php?ip=10.0.6.25&port=502&fcode=3&reg_addr=10304&r_count=2&type=utime"

# 2 registers as a set of bits (coil)
curl -s "http://<host>/modbus/web_socket.php?ip=10.0.6.25&port=502&fcode=3&reg_addr=10304&r_count=2&type=coil"
```

Example of a successful response:

```json
{
  "Error": "0",
  "ErrorText": "",
  "Data": [12345, 6789],
  "DataType": "float",
  "ConvData": 123.45
}
```

For `type=utime`, the response also includes `Time` and `Time1c` fields
(formatted as `YYYY-MM-DD HH:MM:SS` and `YYYYMMDDHHMMSS0000`, respectively).

### Write (`fcode=16`)

```bash
curl -s "http://<host>/modbus/web_socket.php?ip=10.0.6.25&port=502&fcode=16&reg_addr=1000&r_count=2&data=1234,3241"
```

Expected response:

```json
{"Error":"0","ErrorText":""}
```

> ⚠️ Writes modify real device registers. Make sure `reg_addr` points to a
> register that is safe to write. Do not use equipment control registers for
> testing.

## API error codes

| Code | Meaning |
|------|---------|
| 1 | `fcode` was not specified |
| 2 | Unsupported `fcode` (only 3 and 16 are allowed) |
| 3 | `ip` was not specified |
| 6 | `port` was not specified |
| 7 | `reg_addr` was not specified |
| 9 | `r_count` was not specified |
| 10 | Number of values in `data` does not match `r_count` |
| 12 | `data` was not provided for `fcode=16` |
| 13 | Parameter must be a non-negative integer |
| 14 | Numeric parameter or register range is outside the allowed limits |
| 15 | Invalid `type` or `data` value |
| 103 | `modbus.py` returned a read error (for example, no connection to the slave) |
| 116 | `modbus.py` returned a write error |
| 203 | Failed to receive or parse the `modbus.py` response during a read |
| 216 | Failed to receive or parse the `modbus.py` response during a write |

If Python fails to start or returns an invalid response, the API returns a
generic message, while diagnostic information is written to the PHP error log.
The wait for a Python response is limited to five seconds; network
communication time with the device is limited by the Modbus client settings.

## How it works

```text
1C / curl
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

- `web_socket.php` validates parameters and ranges, runs Python with safely
  escaped arguments, and enforces a timeout.
- `modbus.py` reads the arguments, performs the operation through `pymodbus`,
  decodes 32-bit values when needed, and returns JSON.
- PHP parses the JSON and returns it to the client as-is (or wraps it in an
  error with a code from the table above).

## Updating the environment

When dependencies change:

```bash
sudo /usr/share/modbus-api/venv/bin/pip install -r /var/www/modbus-api/requirements.txt
sudo /usr/share/modbus-api/venv/bin/pip freeze > /var/www/modbus-api/requirements.txt
```

Do not commit the virtual environment directory to the repository; commit
`requirements.txt` only. `.gitignore` should contain:

```text
env/
venv/
__pycache__/
*.pyc
```

## Restricting network access

Deploy the API only on a trusted local network and restrict access using the
web server or a firewall. Writes modify real device registers. Before making
the API available on untrusted networks, add authentication and HTTPS.

## Known limitations

- `pymodbus==2.5.3` requires Python ≤ 3.11. Installation may fail on Python
  3.12+ due to outdated dependencies.
- Conversion with `type=float/int/uint/utime/coil` works only with
  `r_count=2` (one 32-bit value uses two 16-bit registers).
- Byte and word order are fixed in `modbus.py`:
  `byteorder=Endian.Big`, `wordorder=Endian.Little`. If your device uses a
  different order, update the corresponding lines.
- For `type=coil`, the bit order is assembled manually
  (`tank_cur_bits1 + tank_cur_bits0 + tank_cur_bits3 + tank_cur_bits2`).
  This is specific to the device; adjust it to match your bit layout if needed.
- `utime` always adds `4*3600` seconds (UTC+4). If your region uses a
  different offset, update `modbus.py`.

## License

Unless stated otherwise, contact the repository owner to clarify the license.
