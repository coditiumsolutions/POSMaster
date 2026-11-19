<?php

namespace App\Http\Controllers;

use App\Services\AppRoles;
use App\Services\NewClientSetupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class NewSetupController extends Controller
{
    public function create(NewClientSetupService $service)
    {
        $user = Auth::user();
        if (!$user || !$user->hasRole(AppRoles::Software)) {
            abort(403);
        }

        return view('setup.new', [
            'configured' => $service->isConfigured(),
            'provider' => $service->provider(),
            'baseDomain' => (string) config('new_setup.base_domain'),
            'result' => session('new_setup_result'),
        ]);
    }

    public function store(Request $request, NewClientSetupService $service)
    {
        $user = Auth::user();
        if (!$user || !$user->hasRole(AppRoles::Software)) {
            abort(403);
        }

        $validated = $request->validate([
            'project_name' => ['required', 'string', 'max:80'],
            'subdomain' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/'],
            'database_name' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_]+$/'],
            'db_password' => ['required', 'string', 'min:8', 'max:64'],
            'admin_username' => ['required', 'string', 'max:50'],
            'admin_password' => ['required', 'string', 'min:6', 'max:64'],
            'confirm_isolated' => ['accepted'],
        ], [
            'confirm_isolated.accepted' => 'You must confirm this will create a separate copy and will not change LipSip.',
            'subdomain.regex' => 'Subdomain may use lowercase letters, numbers, and hyphens only.',
        ]);

        try {
            $result = $service->provision([
                'project_name' => $validated['project_name'],
                'subdomain' => strtolower($validated['subdomain']),
                'database_name' => $validated['database_name'],
                'db_password' => $validated['db_password'],
                'admin_username' => $validated['admin_username'],
                'admin_password' => $validated['admin_password'],
            ]);
        } catch (Throwable $e) {
            return back()
                ->withInput($request->except(['db_password', 'admin_password']))
                ->withErrors(['setup' => $e->getMessage()]);
        }

        return redirect()
            ->route('setup.new')
            ->with('new_setup_result', $result)
            ->with('success', 'New project created successfully.');
    }
}
