<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('about:deployment', function (): void {
    $this->line('Nexus SBI HostGator API. Run migrations explicitly with: php artisan migrate --force');
})->purpose('Show HostGator deployment guidance');
