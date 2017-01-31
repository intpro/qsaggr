<?php

namespace Interpro\QS;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Interpro\Core\Contracts\Taxonomy\Taxonomy;
use Interpro\Core\Ref\ARef;
use Interpro\Core\Taxonomy\Enum\TypeRank;
use Interpro\QS\Exception\QSException;
use Interpro\QS\Executors\Initializer;
use Interpro\QS\Executors\UpdateExecutor;
use Interpro\QS\Model\Group;
use Interpro\QS\Model\Ref;
use Interpro\Core\Contracts\Executor\PredefinedGroupItemsSynchronizer as PredefinedGroupItemsSynchronizerInterface;
use Symfony\Component\Console\Tests\Output\ConsoleOutputTest;

class PredefinedGroupItemsSynchronizer implements PredefinedGroupItemsSynchronizerInterface
{
    private $config;
    private $inConsole = false;
    private $initializer;
    private $updateExecutor;
    private $taxonomy;

    public function __construct(Initializer $initializer, UpdateExecutor $updateExecutor, Taxonomy $taxonomy, array $config)
    {
        $this->config = $config;
        $this->inConsole = App::runningInConsole();
        $this->initializer = $initializer;
        $this->updateExecutor = $updateExecutor;
        $this->taxonomy = $taxonomy;
    }

    public function getFamily()
    {
        return 'qs';
    }

    private function getIdBySlug($name, $slug)
    {
        $item = Group::where('name', $name)->where('slug', $slug)->first();

        if(!$item)
        {
            return false;
        }
        else
        {
            return (int)$item->id;
        }
    }

    /**
     * @return void
     */
    public function sync()
    {
        //[[[
        DB::beginTransaction();

        $output = new \Symfony\Component\Console\Output\ConsoleOutput();

        //Сначала интерпретируем конфиг и создаем элементы
        $pd_items = [];

        foreach($this->config as $group_name => $group_set)
        {
            if(!is_string($group_name))
            {
                throw new QSException('Синхронизатор предопределенных элементов групп QS: имя группы должно быть задано строкой передано '.gettype($group_name).' (ключ на первом уровне)!');
            }

            if(!is_array($group_set))
            {
                throw new QSException('Синхронизатор предопределенных элементов групп QS: настройки группы должны быть заданы массивом передано '.gettype($group_set).' (ключ на первом уровне)!');
            }

            $groupType = $this->taxonomy->getGroup($group_name);

            foreach($group_set as $slug => $refs)
            {
                if(!is_string($slug))
                {
                    throw new QSException('Синхронизатор предопределенных элементов групп QS: имя группы должно быть задано строкой передано '.gettype($slug).' (ключ на втором уровне)!');
                }

                if(!is_array($refs))
                {
                    throw new QSException('Синхронизатор предопределенных элементов групп QS: ссылки элемента группы должны быть заданы массивом передано '.gettype($refs).' (ключ на втором уровне)!');
                }

                $pd_items[$group_name.'_'.$slug] = [];
                $item_ = & $pd_items[$group_name.'_'.$slug];

                $id = $this->getIdBySlug($group_name, $slug);

                if(!$id)
                {
                    $item_['self_ref'] = $this->initializer->init($groupType, ['slug' => $slug, 'predefined' => true, 'sorter' => 0]);

                    $output->writeln('Инициализирован предопределенный элемент '.$slug.' типа '.$group_name.'.');
                }
                else
                {
                    $Aref = new ARef($groupType, $id);

                    //Включаем флаг, если не включен
                    $this->updateExecutor->update($Aref, ['predefined' => true]);

                    $output->writeln('Найденный элемент '.$slug.' типа '.$group_name.': преобразован в предопределенный.');

                    $item_['self_ref'] = $Aref;
                }

                $item_['refs'] = $refs;

            }
        }

        //Затем расставляем ссылки из набора предопределенных

        foreach($pd_items as $pd_key => $current)
        {
            $refs = $current['refs'];
            $self = $current['self_ref'];

            $groupType = $self->getType();
            $group_name = $groupType->getName();
            $id = $self->getId();

            $collection = Ref::where('entity_name', '=', $group_name)->where('entity_id', '=', $id)->get();

            $keyed = $collection->keyBy(function($item)
            {
                return $item->name;
            });

            foreach($refs as $ref_name => $ref_slug)
            {
                $refType = $groupType->getRef($ref_name)->getFieldType();

                $ref_type_name = $refType->getName();

                if($refType->getRank() !== TypeRank::GROUP or $refType->getFamily() !== 'qs')
                {
                    throw new QSException('Тип группы в настройках синхронизатора предопределенных элементов групп QS должен быть группой и принадлеждать пакету QS, передано имя типа '.$ref_type_name.' (ранг: '.$refType->getRank().', пакет: '.$refType->getFamily().')!');
                }

                $ref_key = $ref_type_name.'_'.$ref_slug;

                if(!array_key_exists($ref_key, $pd_items))
                {
                    throw new QSException('Синхронизатор предопределенных элементов групп QS: элемент по ключу '.$ref_type_name.' - '.$ref_slug.' не найден в настройке !');
                }

                $refRef = $pd_items[$ref_key]['self_ref'];
                $ref_id = $refRef->getId();

                if(!$keyed->has($ref_name))
                {
                    $ref = Ref::create(['entity_name' => $group_name, 'entity_id' => $id, 'name' => $ref_name, 'ref_entity_name' => $ref_type_name, 'ref_entity_id' => $ref_id]);

                    $ref->save();

                    $output->writeln('В элементе '.$pd_key.' установлена ссылка '.$ref_name.' типа '.$group_name.' на предопределенный элемент '.$ref_key.'.');
                }
                else
                {
                    $item = $keyed->get($ref_name);

                    if($item->ref_entity_name !== $ref_type_name or (int)$item->ref_entity_id !== $ref_id)
                    {
                        $item->ref_entity_name = $ref_type_name;
                        $item->ref_entity_id = $ref_id;
                        $item->save();

                        $output->writeln('В элементе '.$pd_key.' исправлена ссылка '.$ref_name.' типа '.$group_name.' на предопределенный элемент '.$ref_key.'.');
                    }
                }
            }
        }


        DB::commit();
        //]]]
    }
}
