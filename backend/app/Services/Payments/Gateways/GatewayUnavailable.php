<?php

namespace App\Services\Payments\Gateways;

use RuntimeException;

/** The gateway could not be reached or answered with an error; the caller may retry later. */
final class GatewayUnavailable extends RuntimeException {}
