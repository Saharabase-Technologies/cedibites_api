<?php

namespace App\Services\Uploads;

use RuntimeException;

/**
 * A refusal that is safe to show a person holding a phone.
 *
 * Messages must never confirm what a token points at, or whether a token that
 * does not work ever existed - the public endpoints are reachable by anyone who
 * photographed a screen.
 */
class UploadSessionException extends RuntimeException {}
