<?php

namespace App\Enums;

/** VAT treatment of a category, product or service (spec 05 §5.6). */
enum VatTreatment: string
{
    case Standard = 'standard';
    case ZeroRated = 'zero_rated';
    case Exempt = 'exempt';
}
