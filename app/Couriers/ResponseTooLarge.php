<?php

namespace App\Couriers;

use RuntimeException;

/** Thrown inside the transfer callbacks of AllowlistedCourierHttp to abort a download over its cap; never leaves the client. */
final class ResponseTooLarge extends RuntimeException {}
