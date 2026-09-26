<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const LTI_LOCALE_COOKIE = 'graderai_locale';

    /**
     * @var array<int, string>
     */
    private const ALLOWED_LOCALES = ['pt_BR', 'en'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $cookieLocale = $request->cookie(self::LTI_LOCALE_COOKIE);

        if (is_string($cookieLocale) && in_array($cookieLocale, self::ALLOWED_LOCALES, true)) {
            App::setLocale($cookieLocale);
        } elseif (Session::has('lang')) {
            $sessionLocale = (string) Session::get('lang');

            if (in_array($sessionLocale, self::ALLOWED_LOCALES, true)) {
                App::setLocale($sessionLocale);
            }
        } elseif ($request->routeIs('lti.ai-grader.*')) {
            App::setLocale('pt_BR');
        }

        return $next($request);
    }
}
