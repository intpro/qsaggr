<?php

namespace Interpro\QS;

use Illuminate\Bus\Dispatcher;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Interpro\Core\Contracts\Mediator\DestructMediator;
use Interpro\Core\Contracts\Mediator\InitMediator;
use Interpro\Core\Contracts\Mediator\RefConsistMediator;
use Interpro\Core\Contracts\Mediator\SyncMediator;
use Interpro\Core\Contracts\Mediator\UpdateMediator;
use Interpro\Core\Contracts\Taxonomy\Taxonomy;
use Interpro\Extractor\Contracts\Creation\CItemBuilder;
use Interpro\Extractor\Contracts\Creation\CollectionFactory;
use Interpro\Extractor\Contracts\Db\JoinMediator;
use Interpro\Extractor\Contracts\Db\MappersMediator;
use Interpro\Extractor\Contracts\Selection\Tuner;
use Interpro\QS\Creation\QSItemFactory;
use Interpro\QS\Db\QSAMapper;
use Interpro\QS\Db\QSJoiner;
use Interpro\QS\Db\QSQuerier;
use Interpro\QS\Executors\Destructor;
use Interpro\QS\Executors\Initializer;
use Interpro\QS\Executors\RefConsistExecutor;
use Interpro\QS\Executors\Synchronizer;
use Interpro\QS\Executors\UpdateExecutor;

class QSSecondServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function boot(Dispatcher $dispatcher,
                         Taxonomy $taxonomy,
                         CollectionFactory $collectionFactory,
                         MappersMediator $mappersMediator,
                         JoinMediator $joinMediator,
                         CItemBuilder $cItemBuilder,
                         InitMediator $initMediator,
                         SyncMediator $syncMediator,
                         UpdateMediator $updateMediator,
                         DestructMediator $destructMediator,
                         RefConsistMediator $refConsistMediator,
                         Tuner $tuner)
    {
        //Log::info('Загрузка QSSecondServiceProvider');

        $querier = new QSQuerier($joinMediator);

        //Фабрике нужен медиатор мапперов и строитель item'ов простых типов, QS мапперу нужна фабрика
        $factory = new QSItemFactory($collectionFactory);
        $mapper = new QSAMapper($factory, $collectionFactory, $cItemBuilder, $mappersMediator, $querier, $tuner);

        $mappersMediator->registerAMapper($mapper);

        //joiner нужен для объединения в запросах,
        //при использовании сортировок и фильтров через поле в ссылке на A тип или агрегатный тип B
        $joiner = new QSJoiner($joinMediator);
        $joinMediator->registerJoiner($joiner);

        $initializer = new Initializer($refConsistMediator, $initMediator);
        $initMediator->registerAInitializer($initializer);

        $synchronizer = new Synchronizer($syncMediator, $initMediator);
        $syncMediator->registerASynchronizer($synchronizer);

        $updateExecutor = new UpdateExecutor($refConsistMediator, $updateMediator);
        $updateMediator->registerAUpdateExecutor($updateExecutor);

        $pgi_conf = config('interpro.predefinedqs', []);
        $predefinedGroupItemsSynchronizer = new PredefinedGroupItemsSynchronizer($initializer, $updateExecutor, $taxonomy, $pgi_conf);
        $syncMediator->registerPredefinedGroupItemsSynchronizer($predefinedGroupItemsSynchronizer);

        $destructor = new Destructor($refConsistMediator, $destructMediator);
        $destructMediator->registerADestructor($destructor);

        $refConsistExecutor = new RefConsistExecutor();
        $refConsistMediator->registerRefConsistExecutor($refConsistExecutor);

    }

    /**
     * @return void
     */
    public function register()
    {
        //Log::info('Регистрация QSSecondServiceProvider');

        $config = config('interpro.qs');

        if($config)
        {
            $typeRegistrator = App::make('Interpro\Core\Contracts\Taxonomy\TypeRegistrator');
            $forecastList = App::make('Interpro\Core\Contracts\Taxonomy\TypesForecastList');

            $configInterpreter = new ConfigInterpreter($forecastList);

            $manifests = $configInterpreter->interpretConfig($config);

            foreach($manifests as $manifest)
            {
                $typeRegistrator->registerType($manifest);
            }
        }
    }

}
