<?php

namespace App\Services\Smtp;

readonly class TlsSettings
{
    public function __construct(
        public string $certificatePath,
        public ?string $privateKeyPath = null,
        public ?string $passphrase = null,
        public bool $required = false,
    ) {}
}
