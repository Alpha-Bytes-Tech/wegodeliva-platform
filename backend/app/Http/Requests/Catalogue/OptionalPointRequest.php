<?php

namespace App\Http\Requests\Catalogue;

/** Vendor details: the customer's position is optional and only adds distance/deliverability. */
class OptionalPointRequest extends PointRequest
{
    protected bool $optional = true;
}
