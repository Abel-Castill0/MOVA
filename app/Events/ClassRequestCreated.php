<?php

namespace App\Events;

use App\Models\ClassRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClassRequestCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ClassRequest $classRequest) {}
}
