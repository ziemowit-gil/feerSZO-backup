<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\DTOs\Requests\Testdata\Limits\Context\Session;

use N1ebieski\KSEFClient\Support\AbstractDTO;
use N1ebieski\KSEFClient\Validator\Rules\Number\MaxRule;
use N1ebieski\KSEFClient\Validator\Rules\Number\MinRule;
use N1ebieski\KSEFClient\Validator\Validator;

final class BatchSession extends AbstractDTO
{
    public function __construct(
        public readonly int $maxInvoiceSizeInMB,
        public readonly int $maxInvoiceWithAttachmentSizeInMB,
        public readonly int $maxInvoices
    ) {
        Validator::validate($this->toArray(), [
            'maxInvoiceSizeInMB' => [new MinRule(0), new MaxRule(5)],
            'maxInvoiceWithAttachmentSizeInMB' => [new MinRule(0), new MaxRule(10)],
            'maxInvoices' => [new MinRule(0), new MaxRule(100000)],
        ]);
    }
}
