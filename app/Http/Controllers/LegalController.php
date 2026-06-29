<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class LegalController extends Controller
{
    public function terms()
    {
        return Inertia::render('Legal/Terms');
    }

    public function privacy()
    {
        return Inertia::render('Legal/Privacy');
    }
}
