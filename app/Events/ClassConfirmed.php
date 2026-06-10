<?php

namespace App\Events;

use App\Models\Lesson;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClassConfirmed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Lesson $lesson) {}
}
