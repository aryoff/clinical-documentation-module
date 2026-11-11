<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Exceptions;

use App\Exceptions\UserFacingException;
use LogicException;

final class DiagnosisLineageRefusal extends LogicException implements
    UserFacingException {}
