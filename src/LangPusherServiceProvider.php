<?php

namespace Colomuller91\LaravelLangPusher;

use Illuminate\Support\ServiceProvider;

class LangPusherServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\PushTranslationCommand::class
            ]);
        }
    }
}
