<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class OrganizationController extends Controller
{
    public function index(Request $request): View
    {
        $organizations = Organization::query()
            ->latest()
            ->paginate(15);

        return view('organizations.index', [
            'organizations' => $organizations,
        ]);
    }

    public function create(Request $request): View
    {
        return view('organizations.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:organizations,slug'],
            'timezone' => ['required', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $slug = filled($validated['slug'] ?? null)
            ? Str::slug((string) $validated['slug'])
            : Str::slug((string) $validated['name']);

        Organization::query()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'timezone' => $validated['timezone'],
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()
            ->route('organizations.index')
            ->with('success', 'Organização criada com sucesso.');
    }
}
