<?php

namespace App\Contracts;

interface ActivityLoggable
{
    /**
     * @return array<string, mixed>
     */
    public function toActivityLog(): array;
}
