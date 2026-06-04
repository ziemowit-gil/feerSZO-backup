<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Actions\SplitDocumentIntoParts;

use N1ebieski\KSEFClient\Actions\AbstractHandler;

final class SplitDocumentIntoPartsHandler extends AbstractHandler
{
    /**
     * @return array<int, string>
     */
    public function handle(SplitDocumentIntoPartsAction $action): array
    {
        $documentLength = strlen($action->document);
        $partCount = (int) ceil($documentLength / $action->partSize);
        $partSize = (int) ceil($documentLength / $partCount);

        $parts = [];

        for ($i = 0; $i < $partCount; $i++) {
            $start = $i * $partSize;
            $size = min($partSize, $documentLength - $start);

            if ($size <= 0) {
                break;
            }

            $part = substr($action->document, $start, $size);
            $parts[] = $part;
        }

        return $parts;
    }
}
