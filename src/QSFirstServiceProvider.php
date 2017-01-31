<?php

namespace Interpro\QS;

use Illuminate\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class QSFirstServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function boot(Dispatcher $dispatcher)
    {
        //Log::info('Загрузка QSFirstServiceProvider');

        $this->publishes([__DIR__.'/config/qs.php' => config_path('interpro/qs.php')]);
        $this->publishes([__DIR__.'/config/predefinedqs.php' => config_path('interpro/predefinedqs.php')]);

        $this->publishes([
            __DIR__.'/migrations' => database_path('migrations')
        ], 'migrations');
    }

    /**
     * @return void
     */
    public function register()
    {
       //Log::info('Регистрация QSFirstServiceProvider');

        //Регистрируем имена, для интерпретации типов при загрузке
        $forecastList = $this->app->make('Interpro\Core\Contracts\Taxonomy\TypesForecastList');

        $qs = config('interpro.qs', []);
        if($qs)
        {
            foreach($qs as $block_name => $attr)
            {
                $forecastList->registerATypeName($block_name);

                if(array_key_exists('groups', $attr))
                {
                    foreach($attr['groups'] as $group_name => $gattr)
                    {
                        $forecastList->registerATypeName($group_name);
                    }
                }
            }
        }
    }

}
