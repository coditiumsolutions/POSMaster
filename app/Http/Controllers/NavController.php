<?php

namespace App\Http\Controllers;

use App\Services\AppRoles;
use App\Services\NavigationCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

class NavController extends Controller
{
    public function select(Request $request, string $key)
    {
        $area = NavigationCatalog::findArea($key);
        $to = $request->query('to');
        $targetsSetup = strcasecmp($area['key'], 'Setup') === 0
            || (is_string($to) && str_starts_with($to, 'setup.'));

        if ($targetsSetup && (!Auth::user() || !Auth::user()->hasRole(AppRoles::Software))) {
            abort(403, 'Access denied. Software role required.');
        }

        session([NavigationCatalog::SessionKey => $area['key']]);

        // Optional deep-link: /nav/select/AllModules?to=vendors.index
        if (is_string($to) && Route::has($to)) {
            return redirect()->route($to);
        }

        // All Modules hub page
        if ($area['key'] === 'AllModules' && Route::has('modules.index')) {
            return redirect()->route('modules.index');
        }

        $first = collect($area['links'])->first(function ($link) {
            return empty($link['children']) && !empty($link['route']);
        });
        if ($first && !empty($first['route'])) {
            return redirect()->route($first['route']);
        }

        return redirect()->route('dashboard');
    }

    public function clear()
    {
        session([NavigationCatalog::SessionKey => 'Dashboard']);
        return redirect()->route('dashboard');
    }
}
