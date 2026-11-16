<?php
namespace Tests\Support;

/** El servidor HTTP de ensayo debe comprobar CSRF incluso con APP_ENV=testing. */
final class PilotDemoHttpCsrf extends \Illuminate\Foundation\Http\Middleware\PreventRequestForgery
{
    protected function runningUnitTests() { return false; }
}
