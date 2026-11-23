<?php

namespace App\Events;

/** An admin asked a vendor or rider for a document. */
final class DocumentRequested
{
    public function __construct(public readonly int $documentId) {}
}
