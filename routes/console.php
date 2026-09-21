<?php
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Inspiring;
Artisan::command('inspire', fn () => $this->comment(Inspiring::quote()))->purpose('Display an inspiring quote');
