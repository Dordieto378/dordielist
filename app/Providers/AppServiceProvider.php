<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {

        if($this->app->environment('production')) {
            URL::forceRootUrl(config('app.url')); 
            URL::forceScheme('https');
        }

    }
}
