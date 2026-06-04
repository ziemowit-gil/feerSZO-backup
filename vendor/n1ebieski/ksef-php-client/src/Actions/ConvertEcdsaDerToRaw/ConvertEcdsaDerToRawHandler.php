<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Actions\ConvertEcdsaDerToRaw;

use InvalidArgumentException;
use N1ebieski\KSEFClient\Actions\AbstractHandler;

final class ConvertEcdsaDerToRawHandler extends AbstractHandler
{
    /**
     * Convert ECDSA DER to raw (r||s)
     */
    public function handle(ConvertEcdsaDerToRawAction $action): string
    {
        $data = unpack('C*', $action->der);

        if ($data === false) {
            throw new InvalidArgumentException('Invalid DER');
        }

        /** @var array<int, int> $data */
        $data = array_values($data);
        $offset = 0;

        if ($data[$offset++] != 0x30) {
            throw new InvalidArgumentException("Invalid DER: no SEQUENCE");
        }

        $seqLen = $data[$offset++];
        if ($seqLen & 0x80) { //@phpstan-ignore-line
            $lenBytes = $seqLen & 0x7F;
            $seqLen = 0;
            for ($i = 0; $i < $lenBytes; $i++) {
                $seqLen = ($seqLen << 8) | $data[$offset++];
            }
        }

        // INTEGER r
        if ($data[$offset++] != 0x02) {
            throw new InvalidArgumentException("Invalid DER: expected INTEGER (r)");
        }

        $rLen = $data[$offset++];
        $r = '';
        for ($i = 0; $i < $rLen; $i++) {
            $r .= chr($data[$offset++]);
        }

        // INTEGER s
        if ($data[$offset++] != 0x02) {
            throw new InvalidArgumentException("Invalid DER: expected INTEGER (s)");
        }

        $sLen = $data[$offset++];
        $s = '';
        for ($i = 0; $i < $sLen; $i++) {
            $s .= chr($data[$offset++]);
        }

        // dopasowanie do długości krzywej (pad zeros)
        $r = str_pad(ltrim($r, "\x00"), $action->keySize, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), $action->keySize, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }
}
