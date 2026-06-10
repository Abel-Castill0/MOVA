<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ParentSettingsController extends Controller
{
    public function update(Request $request)
    {
        $request->validate(['parental_control' => 'required|boolean']);
        auth()->user()->update(['parental_control' => $request->parental_control]);
        return back()->with('success', 'Configuración actualizada.');
    }
}
