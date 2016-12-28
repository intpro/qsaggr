<?php

namespace Interpro\QS;

use Illuminate\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Interpro\Core\Contracts\Mediator\DestructMediator;
use Interpro\Core\Contracts\Mediator\InitMediator;
use Interpro\Core\Contracts\Mediator\RefConsistMediator;
use Interpro\Core\Contracts\Mediator\UpdateMediator;
use Interpro\Core\Contracts\Taxonomy\TypeRegistrator;
use Interpro\Core\Contracts\Taxonomy\TypesForecastList;
use Interpro\Extractor\Contracts\Creation\CItemBuilder;
use Interpro\Extractor\Contracts\Creation\CollectionFactory;
use Interpro\Extractor\Contracts\Db\JoinMediator;
use Interpro\Extractor\Contracts\Db\MappersMediator;
use Interpro\QS\Creation\QSItemFactory;
use Interpro\QS\Db\QSAMapper;
use Interpro\QS\Db\QSJoiner;
use Interpro\QS\Db\QSQuerier;
use Interpro\QS\Executors\Destructor;
use Interpro\QS\Executors\Initializer;
use Interpro\QS\Executors\RefConsistExecutor;
use Interpro\QS\Executors\UpdateExecutor;

class QSFirstServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function boot(Dispatcher $dispatcher)
    {
        Log::info('Загрузка QSFirstServiceProvider');

        $this->publishes([__DIR__.'/config/qs.php' => config_path('interpro/qs.php')]);

        $this->publishes([
            __DIR__.'/migrations' => database_path('migrations')
        ], 'migrations');
    }

    /**
     * @return void
     */
    public function register()
    {
        Log::info('Регистрация QSFirstServiceProvider');

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
