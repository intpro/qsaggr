<?php

namespace Interpro\QS\Executors;

use Illuminate\Support\Facades\DB;
use Interpro\Core\Contracts\Executor\ASynchronizer as ASynchronizerInterface;
use Interpro\Core\Contracts\Mediator\InitMediator;
use Interpro\Core\Contracts\Mediator\SyncMediator;
use Interpro\Core\Contracts\Taxonomy\Types\AType;
use Interpro\Core\Ref\ARef;
use Interpro\Core\Taxonomy\Enum\TypeRank;
use Interpro\QS\Model\Block;
use Interpro\QS\Model\Group;
use Interpro\QS\Model\Ref;

class Synchronizer implements ASynchronizerInterface
{
    private $initMediator;
    private $syncMediator;

    public function __construct(SyncMediator $syncMediator, InitMediator $initMediator)
    {
        $this->initMediator = $initMediator;
        $this->syncMediator = $syncMediator;
    }

    /**
     * @return string
     */
    public function getFamily()
    {
        return 'qs';
    }

    private function syncEntity(AType $type, $id)
    {
        $aRef = new ARef($type, $id);

        $self_owns = ['id', 'name', 'slug', 'title', 'sorter', 'show'];

        $owns = $type->getOwns();

        foreach($owns as $own)
        {
            $name = $own->getName();

            if(in_array($name, $self_owns))
            {
                continue;
            }

            $family = $own->getFieldTypeFamily();

            $synchronizer = $this->syncMediator->getOwnSynchronizer($family);

            $synchronizer->sync($aRef, $own);
        }
    }

    private function syncRefs(AType $type, $id)
    {
        $type_name = $type->getName();

        $collection = Ref::where('entity_name', '=', $type_name)->where('entity_id', '=', $id)->get();

        $keyed = $collection->keyBy(function($item)
        {
            return $item->name;
        });

        $refs = $type->getRefs();

        foreach($refs as $ref_name => $ref)
        {
            $ref_type_name = $ref->getFieldTypeName();

            if(!$keyed->has($ref_name))
            {
                $ref = Ref::create(['entity_name' => $type_name, 'entity_id' => $id, 'name' => $ref_name, 'ref_entity_name' => $ref_type_name, 'ref_entity_id' => 0]);

                $ref->save();
            }
            else
            {
                $item = $keyed->get($ref_name);

                if($item->ref_entity_name !== $ref_type_name)
                {
                    $item->ref_entity_name = $ref_type_name;
                    $item->ref_entity_id = 0;
                    $item->save();
                }
            }
        }
    }

    /**
     * @param \Interpro\Core\Contracts\Taxonomy\Types\AType $type
     *
     * @return \Interpro\Core\Contracts\Ref\ARef
     */
    public function sync(AType $type)
    {
        $type_name = $type->getName();

        //[[[
        DB::beginTransaction();

        if($type->getRank() === TypeRank::BLOCK)
        {
            $model = Block::where('name', '=', $type_name)->first();
            if(!$model)
            {
                $initializer = $this->initMediator->getAInitializer($type->getFamily());

                //Проинициализируется по текущему состоянию конфигурации, не надо синхронизировать
                $blockRef = $initializer->init($type);
            }
            else
            {
                $this->syncEntity($type, 0);
                $this->syncRefs($type, 0);
            }
        }
        elseif($type->getRank() === TypeRank::GROUP)
        {
            $collection = Group::where('name', '=', $type_name)->get();

            foreach($collection as $item)
            {
                $this->syncEntity($type, $item->id);
                $this->syncRefs($type, $item->id);
            }
        }

        DB::commit();
        //]]]
    }
}
