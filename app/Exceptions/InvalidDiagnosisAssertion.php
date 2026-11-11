<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Exceptions;

use App\Exceptions\UserFacingException;
use InvalidArgumentException;

final class InvalidDiagnosisAssertion
    extends InvalidArgumentException
    implements UserFacingException {}
