<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class LocaleController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_LOCALES = ['pt_BR', 'en'];

    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, self::ALLOWED_LOCALES, true), 404);

        $request->session()->put('lang', $locale);

        return redirect()->back()->with('locale', $locale);
    }
}
