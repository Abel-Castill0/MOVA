<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class TeacherInvitationController extends Controller
{
    public function index()
    {
        return Inertia::render('Landing/TeacherInvitation');
    }
}
