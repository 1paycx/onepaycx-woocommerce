<?php
/**
 * EVM payout address checks for wallet mode.
 *
 * Every card payout and every EVM coin a wallet store sells is sent to the address the
 * owner pastes, so a typo is real money:
 * - the zero address (0x000…0) is refused: nobody holds its key, anything sent there is lost;
 * - a mixed-case address carries an EIP-55 checksum (each letter's case encodes a bit of the
 *   Keccak-256 hash of the lower-case address), which is verified. All lower case or all upper
 *   case carries no checksum and is accepted as typed.
 *
 * PHP has no Keccak-256 built in (its sha3-256 uses different padding), so a small
 * implementation follows. It needs 64-bit integers; on a 32-bit PHP build the checksum is
 * not checked here (1pay.cx checks it again when the address is saved).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OnePayCx_Evm {
    const FORMAT_RE = '/^0x[0-9a-fA-F]{40}$/';

    /** Keccak-f[1600] rotation offsets, indexed by x + 5y. */
    const ROTATIONS = array(0, 1, 62, 28, 27, 36, 44, 6, 55, 20, 3, 10, 43, 25, 39, 41, 45, 15, 21, 8, 18, 2, 61, 56, 14);

    /** Keccak-f[1600] round constants as [high 32 bits, low 32 bits] (PHP has no unsigned 64-bit literals). */
    const ROUND_CONSTANTS = array(
        array(0x00000000, 0x00000001), array(0x00000000, 0x00008082), array(0x80000000, 0x0000808A), array(0x80000000, 0x80008000),
        array(0x00000000, 0x0000808B), array(0x00000000, 0x80000001), array(0x80000000, 0x80008081), array(0x80000000, 0x00008009),
        array(0x00000000, 0x0000008A), array(0x00000000, 0x00000088), array(0x00000000, 0x80008009), array(0x00000000, 0x8000000A),
        array(0x00000000, 0x8000808B), array(0x80000000, 0x0000008B), array(0x80000000, 0x00008089), array(0x80000000, 0x00008003),
        array(0x80000000, 0x00008002), array(0x80000000, 0x00000080), array(0x00000000, 0x0000800A), array(0x80000000, 0x8000000A),
        array(0x80000000, 0x80008081), array(0x80000000, 0x00008080), array(0x00000000, 0x80000001), array(0x80000000, 0x80008008),
    );

    /**
     * What is wrong with $address as a payout address: '' when nothing, else 'format'
     * (not 0x plus 40 hex digits), 'zero' or 'checksum'.
     */
    public static function problem($address) {
        if (!is_string($address) || 1 !== preg_match(self::FORMAT_RE, $address)) {
            return 'format';
        }
        $hex = substr($address, 2);
        if (1 === preg_match('/^0{40}$/', $hex)) {
            return 'zero';
        }
        $lower = strtolower($hex);
        if ($hex === $lower || $hex === strtoupper($hex) || PHP_INT_SIZE < 8) {
            return '';
        }
        $hash = self::keccak256($lower);
        for ($i = 0; $i < 40; $i++) {
            $ch = $hex[$i];
            if (ctype_digit($ch)) {
                continue;
            }
            $upper = hexdec($hash[$i]) >= 8;
            if (ctype_upper($ch) !== $upper) {
                return 'checksum';
            }
        }
        return '';
    }

    /** Keccak-256 (the original padding Ethereum uses, not NIST SHA3-256) of $message, as lower-case hex. */
    public static function keccak256($message) {
        $rate  = 136;
        $state = array_fill(0, 25, 0);
        $message .= "\x01" . str_repeat("\x00", ($rate - (strlen($message) + 1) % $rate) % $rate);
        $message[strlen($message) - 1] = chr(ord($message[strlen($message) - 1]) | 0x80);
        for ($offset = 0, $n = strlen($message); $offset < $n; $offset += $rate) {
            for ($lane = 0; $lane < 17; $lane++) {
                $state[$lane] ^= self::lane(substr($message, $offset + $lane * 8, 8));
            }
            self::permute($state);
        }
        $out = '';
        for ($lane = 0; $lane < 4; $lane++) {
            for ($byte = 0; $byte < 8; $byte++) {
                $out .= chr(($state[$lane] >> (8 * $byte)) & 0xFF);
            }
        }
        return bin2hex($out);
    }

    /** Eight bytes, little-endian, as one 64-bit lane. */
    private static function lane($bytes) {
        $v = 0;
        for ($i = 7; $i >= 0; $i--) {
            $v = ($v << 8) | ord($bytes[$i]);
        }
        return $v;
    }

    /** 64-bit rotate left. PHP's >> is arithmetic, so the shifted-in sign bits are masked off. */
    private static function rotl($v, $n) {
        if (0 === $n) {
            return $v;
        }
        return ($v << $n) | (($v >> (64 - $n)) & ~(-1 << $n));
    }

    private static function permute(array &$a) {
        foreach (self::ROUND_CONSTANTS as $rc) {
            // theta
            $c = array();
            for ($x = 0; $x < 5; $x++) {
                $c[$x] = $a[$x] ^ $a[$x + 5] ^ $a[$x + 10] ^ $a[$x + 15] ^ $a[$x + 20];
            }
            for ($x = 0; $x < 5; $x++) {
                $d = $c[($x + 4) % 5] ^ self::rotl($c[($x + 1) % 5], 1);
                for ($y = 0; $y < 25; $y += 5) {
                    $a[$x + $y] ^= $d;
                }
            }
            // rho and pi
            $b = array();
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $b[$y + 5 * ((2 * $x + 3 * $y) % 5)] = self::rotl($a[$x + 5 * $y], self::ROTATIONS[$x + 5 * $y]);
                }
            }
            // chi
            for ($y = 0; $y < 25; $y += 5) {
                for ($x = 0; $x < 5; $x++) {
                    $a[$x + $y] = $b[$x + $y] ^ (~$b[($x + 1) % 5 + $y] & $b[($x + 2) % 5 + $y]);
                }
            }
            // iota
            $a[0] ^= ($rc[0] << 32) | $rc[1];
        }
    }
}
