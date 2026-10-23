<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountDeletionRequest;
use App\Services\AccountLifecycleService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AccountDeletionController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/AccountDeletions', [
            'requests' => AccountDeletionRequest::with('user:id,name,email')->latest()->paginate(20),
        ]);
    }

    public function update(Request $request, AccountDeletionRequest $deletion, AccountLifecycleService $lifecycle)
    {
        $data = $request->validate(['response' => ['required', 'string', 'min:20', 'max:5000']]);
        $lifecycle->respond($deletion, $request->user(), $data['response']);
        return back()->with('success', 'Respuesta registrada y disponible en el estado de la solicitud.');
    }
}
