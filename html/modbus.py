#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
modbus.py

Выполняет чтение и запись регистров Modbus-TCP, возвращая результат в JSON.
Скрипт вызывается web_socket.php и использует pymodbus 2.5.3.

Автор: Pavel Dik
Версия: 1.0
"""

import json
import logging
import re
import sys
from datetime import datetime

from pymodbus.client.sync import ModbusTcpClient
from pymodbus.constants import Endian
from pymodbus.payload import BinaryPayloadBuilder, BinaryPayloadDecoder


MAX_READ_REGISTERS = 125
MAX_WRITE_REGISTERS = 123


def read_register(server, port, reg_addr, count):
    client = ModbusTcpClient(server, port=port, timeout=1, retries=0)
    try:
        if not client.connect():
            return 2, None
        response = client.read_holding_registers(reg_addr, count=count, slave=0)
        if response.isError():
            return 3, None
        return 0, response
    except Exception:
        logging.exception('Ошибка выполнения запроса чтения Modbus')
        return 4, None
    finally:
        try:
            client.close()
        except Exception:
            pass


def write_register(server, port, reg_addr, payload):
    client = ModbusTcpClient(server, port=port, timeout=1, retries=0)
    try:
        if not client.connect():
            return 2
        response = client.write_registers(reg_addr, payload, skip_encode=True)
        return 3 if response.isError() else 0
    except Exception:
        logging.exception('Ошибка выполнения запроса записи Modbus')
        return 4
    finally:
        try:
            client.close()
        except Exception:
            pass


def error_result(error, text):
    return {'Error': str(error), 'ErrorText': text}


def parse_args(args):
    if len(args) != 6:
        raise ValueError('Ожидается шесть параметров')

    action, server, raw_port, raw_addr, raw_count, value = args
    if action not in ('3', '16') or not server:
        raise ValueError('Некорректная Modbus-команда или адрес устройства')
    if any(not re.fullmatch(r'[0-9]+', raw) for raw in (raw_port, raw_addr, raw_count)):
        raise ValueError('Числовые параметры должны содержать только цифры')
    port, reg_addr, count = int(raw_port), int(raw_addr), int(raw_count)
    max_count = MAX_READ_REGISTERS if action == '3' else MAX_WRITE_REGISTERS
    if not 1 <= port <= 65535:
        raise ValueError('Порт должен быть в диапазоне от 1 до 65535')
    if not 0 <= reg_addr <= 65535 or not 1 <= count <= max_count:
        raise ValueError('Недопустимый адрес или размер диапазона регистров')
    if reg_addr + count > 65536:
        raise ValueError('Диапазон регистров выходит за допустимый адрес 65535')
    return action, server, port, reg_addr, count, value


def decode_registers(registers, conv_type):
    byte_order, word_order = Endian.Big, Endian.Little
    decoder = BinaryPayloadDecoder.fromRegisters(registers, byte_order, word_order)
    if conv_type == 'float':
        return decoder.decode_32bit_float()
    if conv_type == 'int':
        return decoder.decode_32bit_int()
    if conv_type in ('uint', 'utime'):
        return decoder.decode_32bit_uint()
    if conv_type == 'coil':
        decoder = BinaryPayloadDecoder.fromRegisters(
            registers, Endian.Big, Endian.Big
        )
        bits = [decoder.decode_bits() for _ in range(4)]
        return bits[1] + bits[0] + bits[3] + bits[2]
    return None


def execute(args):
    action, server, port, reg_addr, count, value = parse_args(args)
    if action == '16':
        words = value.split(',')
        if len(words) != count:
            return error_result(1, 'Количество данных не совпадает с количеством регистров')
        if any(not re.fullmatch(r'[0-9]+', word) for word in words):
            return error_result(1, 'Данные должны быть целыми числами от 0 до 65535')
        try:
            values = [int(word) for word in words]
        except ValueError:
            return error_result(1, 'Данные должны быть целыми числами от 0 до 65535')
        if any(word < 0 or word > 65535 for word in values):
            return error_result(1, 'Данные должны быть целыми числами от 0 до 65535')

        builder = BinaryPayloadBuilder(byteorder=Endian.Big, wordorder=Endian.Little)
        for word in values:
            builder.add_16bit_uint(word)
        result = write_register(server, port, reg_addr, builder.build())
        if result == 0:
            return error_result(0, '')
        messages = {
            2: 'Не удалось соединиться с Modbus-Slave',
            3: 'Modbus-Slave вернул ошибку при записи',
            4: 'Внутренняя ошибка при записи Modbus-регистров',
        }
        return error_result(1, messages[result])

    conv_type = value.lower()
    if conv_type not in ('none', 'float', 'int', 'uint', 'utime', 'coil'):
        return error_result(1, 'Недопустимый тип преобразования')
    result, response = read_register(server, port, reg_addr, count)
    if result:
        messages = {
            2: 'Не удалось соединиться с Modbus-Slave',
            3: 'Modbus-Slave вернул ошибку при чтении',
            4: 'Внутренняя ошибка при чтении Modbus-регистров',
        }
        return error_result(1, messages[result])

    registers = response.registers
    output = error_result(0, '')
    output['Data'] = registers
    if conv_type != 'none' and count == 2:
        output['DataType'] = conv_type
        converted = decode_registers(registers, conv_type)
        if converted is not None:
            output['ConvData'] = converted
        if conv_type == 'utime':
            timestamp = output['ConvData'] + 4 * 3600
            output['Time'] = datetime.utcfromtimestamp(timestamp).strftime('%Y-%m-%d %H:%M:%S')
            output['Time1c'] = datetime.utcfromtimestamp(timestamp).strftime('%Y%m%d%H%M%S0000')
    return output


def main():
    try:
        result = execute(sys.argv[1:])
    except (ValueError, OverflowError, IndexError):
        result = error_result(1, 'Некорректные параметры Modbus-запроса')
    except Exception:
        logging.exception('Внутренняя ошибка обработки Modbus-запроса')
        result = error_result(1, 'Внутренняя ошибка обработки Modbus-запроса')
    try:
        output = json.dumps(result, allow_nan=False)
    except (TypeError, ValueError):
        logging.exception('Не удалось сериализовать ответ Modbus в JSON')
        output = json.dumps(error_result(1, 'Не удалось сформировать JSON-ответ'))
    print(output)


if __name__ == '__main__':
    main()
