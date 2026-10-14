<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

class CookieCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return ! config('ams.enforce_csrf_in_tests', false) && parent::runningUnitTests();
    }
}
