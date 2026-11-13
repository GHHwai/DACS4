<?php

namespace App\Chat;

use RuntimeException;

/**
 * Minimal RFC 6455 implementation: handshake key derivation plus frame
 * encode / decode. Only what a chat gateway needs (text, ping, pong, close).
 */
final class WebSocketCodec
{
    public const OP_CONT = 0x0;
    public const OP_TEXT = 0x1;
    public const OP_BINARY = 0x2;
    public const OP_CLOSE = 0x8;
    public const OP_PING = 0x9;
    public const OP_PONG = 0xA;

    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public static function acceptKey(string $clientKey): string
    {
        return base64_encode(sha1($clientKey.self::GUID, true));
    }

    /** Encode a server -> client frame (servers never mask). */
    public static function encode(string $payload, int $opcode = self::OP_TEXT): string
    {
        $len = strlen($payload);
        $first = chr(0x80 | $opcode); // FIN + opcode

        if ($len < 126) {
            return $first.chr($len).$payload;
        }
        if ($len < 65536) {
            return $first.chr(126).pack('n', $len).$payload;
        }

        return $first.chr(127).pack('J', $len).$payload;
    }

    /**
     * Pull every complete frame out of $buffer (which is shortened in place).
     *
     * @return list<array{0:int,1:bool,2:string}>  [opcode, fin, payload]
     *
     * @throws RuntimeException on a protocol violation (caller should close the socket)
     */
    public static function decode(string &$buffer, int $maxPayload): array
    {
        $frames = [];

        while (true) {
            $available = strlen($buffer);
            if ($available < 2) {
                break;
            }

            $b1 = ord($buffer[0]);
            $b2 = ord($buffer[1]);
            $fin = ($b1 & 0x80) !== 0;
            $opcode = $b1 & 0x0F;
            $masked = ($b2 & 0x80) !== 0;
            $len = $b2 & 0x7F;
            $offset = 2;

            if (($b1 & 0x70) !== 0) {
                throw new RuntimeException('Reserved bits set');
            }
            if (! $masked) {
                throw new RuntimeException('Client frames must be masked');
            }

            if ($len === 126) {
                if ($available < 4) {
                    break;
                }
                $len = unpack('n', substr($buffer, 2, 2))[1];
                $offset = 4;
            } elseif ($len === 127) {
                if ($available < 10) {
                    break;
                }
                $len = unpack('J', substr($buffer, 2, 8))[1];
                $offset = 10;
                if ($len < 0) {
                    throw new RuntimeException('Invalid frame length');
                }
            }

            if ($opcode >= 0x8 && ($len > 125 || ! $fin)) {
                throw new RuntimeException('Invalid control frame');
            }
            if ($len > $maxPayload) {
                throw new RuntimeException('Frame too large');
            }
            if ($available < $offset + 4 + $len) {
                break; // wait for the rest
            }

            $mask = substr($buffer, $offset, 4);
            $payload = substr($buffer, $offset + 4, $len);
            // XOR of two strings stops at the shorter one, so over-repeating the mask is safe.
            $payload = $payload ^ str_repeat($mask, intdiv($len, 4) + 1);
            $buffer = (string) substr($buffer, $offset + 4 + $len);

            $frames[] = [$opcode, $fin, $payload];
        }

        return $frames;
    }
}
