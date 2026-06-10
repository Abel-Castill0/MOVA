<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class StudentInvitationController extends Controller
{
    public function index()
    {
        return Inertia::render('Landing/StudentInvitation');
    }
}
