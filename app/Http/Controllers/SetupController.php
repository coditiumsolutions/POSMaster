<?php

namespace App\Http\Controllers;

use App\Services\AppRoles;
use App\Services\NavigationCatalog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

class SetupController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        if (!$user || !$user->hasRole(AppRoles::Software)) {
            abort(403, 'Access denied. Software role required.');
        }

        session([NavigationCatalog::SessionKey => 'Setup']);

        $modules = array_values(array_filter(NavigationCatalog::setupModules(), function ($m) {
            return !empty($m['route']) && Route::has($m['route']);
        }));
        return view('setup.index', compact('modules'));
    }
}
