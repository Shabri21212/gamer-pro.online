<?php
declare(strict_types=1);

/**
 * scrypt как у Node crypto / better-auth:
 * N=16384, r=16, p=1, dkLen=64, соль — hex-строка как байты.
 */
final class Scrypt
{
    public static function verifyBetterAuth(string $stored, string $password): bool
    {
        $parts = explode(':', $stored, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }
        [$salt, $keyHex] = $parts;
        if (!ctype_xdigit($keyHex) || strlen($keyHex) % 2 !== 0) {
            return false;
        }
        $normalized = class_exists('Normalizer')
            ? (Normalizer::normalize($password, Normalizer::FORM_KC) ?: $password)
            : $password;
        $got = self::hashFast($normalized, $salt) ?? self::hash($normalized, $salt, 16384, 16, 1, 64);
        return hash_equals(strtolower($keyHex), bin2hex($got));
    }

    /** libsodium через FFI — ~70 мс. На shared-хостинге часто выключен. */
    private static function hashFast(string $password, string $salt): ?string
    {
        if (!class_exists('FFI')) {
            return null;
        }
        try {
            $ffi = \FFI::cdef(
                'int crypto_pwhash_scryptsalsa208sha256_ll(const unsigned char *passwd, unsigned long passwdlen, const unsigned char *salt, unsigned long saltlen, unsigned long long N, unsigned int r, unsigned int p, unsigned char *buf, unsigned long buflen);',
                'libsodium.so.23',
            );
            $plen = strlen($password);
            $slen = strlen($salt);
            $pbuf = \FFI::new('unsigned char[' . max($plen, 1) . ']', false, true);
            $sbuf = \FFI::new('unsigned char[' . max($slen, 1) . ']', false, true);
            if ($plen > 0) {
                \FFI::memcpy($pbuf, $password, $plen);
            }
            if ($slen > 0) {
                \FFI::memcpy($sbuf, $salt, $slen);
            }
            $out = \FFI::new('unsigned char[64]');
            $rc = $ffi->crypto_pwhash_scryptsalsa208sha256_ll($pbuf, $plen, $sbuf, $slen, 16384, 16, 1, $out, 64);
            if ($rc !== 0) {
                return null;
            }
            return \FFI::string($out, 64);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function hash(string $password, string $salt, int $N, int $r, int $p, int $dkLen): string
    {
        $blockSize = 128 * $r;
        $B = hash_pbkdf2('sha256', $password, $salt, 1, $p * $blockSize, true);
        $out = '';
        for ($i = 0; $i < $p; $i++) {
            $out .= self::romix(substr($B, $i * $blockSize, $blockSize), $N, $r);
        }
        return hash_pbkdf2('sha256', $password, $out, 1, $dkLen, true);
    }

    private static function romix(string $B, int $N, int $r): string
    {
        $V = [];
        $X = $B;
        for ($i = 0; $i < $N; $i++) {
            $V[$i] = $X;
            $X = self::blockMix($X, $r);
        }
        for ($i = 0; $i < $N; $i++) {
            $j = self::integerify($X, $r) & ($N - 1);
            $X = self::blockMix(self::xorStr($X, $V[$j]), $r);
        }
        return $X;
    }

    private static function integerify(string $B, int $r): int
    {
        $last = substr($B, 128 * $r - 64, 8);
        $n = unpack('V2', $last);
        return $n[1];
    }

    private static function xorStr(string $a, string $b): string
    {
        return $a ^ $b;
    }

    private static function blockMix(string $B, int $r): string
    {
        $X = substr($B, 64 * (2 * $r - 1), 64);
        $Yeven = '';
        $Yodd = '';
        for ($i = 0; $i < 2 * $r; $i++) {
            $Bi = substr($B, 64 * $i, 64);
            $X = self::salsa208(self::xorStr($X, $Bi));
            if (($i % 2) === 0) {
                $Yeven .= $X;
            } else {
                $Yodd .= $X;
            }
        }
        return $Yeven . $Yodd;
    }

    private static function salsa208(string $input): string
    {
        $x = array_values(unpack('V16', $input));
        $z = $x;
        for ($i = 0; $i < 4; $i++) {
            $z[4] ^= self::rot($z[0] + $z[12], 7);
            $z[8] ^= self::rot($z[4] + $z[0], 9);
            $z[12] ^= self::rot($z[8] + $z[4], 13);
            $z[0] ^= self::rot($z[12] + $z[8], 18);
            $z[9] ^= self::rot($z[5] + $z[1], 7);
            $z[13] ^= self::rot($z[9] + $z[5], 9);
            $z[1] ^= self::rot($z[13] + $z[9], 13);
            $z[5] ^= self::rot($z[1] + $z[13], 18);
            $z[14] ^= self::rot($z[10] + $z[6], 7);
            $z[2] ^= self::rot($z[14] + $z[10], 9);
            $z[6] ^= self::rot($z[2] + $z[14], 13);
            $z[10] ^= self::rot($z[6] + $z[2], 18);
            $z[3] ^= self::rot($z[15] + $z[11], 7);
            $z[7] ^= self::rot($z[3] + $z[15], 9);
            $z[11] ^= self::rot($z[7] + $z[3], 13);
            $z[15] ^= self::rot($z[11] + $z[7], 18);
            $z[1] ^= self::rot($z[0] + $z[3], 7);
            $z[2] ^= self::rot($z[1] + $z[0], 9);
            $z[3] ^= self::rot($z[2] + $z[1], 13);
            $z[0] ^= self::rot($z[3] + $z[2], 18);
            $z[6] ^= self::rot($z[5] + $z[4], 7);
            $z[7] ^= self::rot($z[6] + $z[5], 9);
            $z[4] ^= self::rot($z[7] + $z[6], 13);
            $z[5] ^= self::rot($z[4] + $z[7], 18);
            $z[11] ^= self::rot($z[10] + $z[9], 7);
            $z[8] ^= self::rot($z[11] + $z[10], 9);
            $z[9] ^= self::rot($z[8] + $z[11], 13);
            $z[10] ^= self::rot($z[9] + $z[8], 18);
            $z[12] ^= self::rot($z[15] + $z[14], 7);
            $z[13] ^= self::rot($z[12] + $z[15], 9);
            $z[14] ^= self::rot($z[13] + $z[12], 13);
            $z[15] ^= self::rot($z[14] + $z[13], 18);
        }
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= pack('V', ($z[$i] + $x[$i]) & 0xFFFFFFFF);
        }
        return $out;
    }

    private static function rot(int $a, int $n): int
    {
        $a &= 0xFFFFFFFF;
        return (($a << $n) | ($a >> (32 - $n))) & 0xFFFFFFFF;
    }
}
