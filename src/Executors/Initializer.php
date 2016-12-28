<?php

namespace Interpro\QS\Executors;

use Illuminate\Support\Facades\DB;
use Interpro\Core\Contracts\Executor\AInitializer;
use Interpro\Core\Contracts\Mediator\InitMediator;
use Interpro\Core\Contracts\Mediator\RefConsistMediator;
use Interpro\Core\Contracts\Taxonomy\Types\AType;
use Interpro\Core\Ref\ARef;
use Interpro\Core\Taxonomy\Enum\TypeMode;
use Interpro\Core\Taxonomy\Enum\TypeRank;
use Interpro\QS\Exception\QSException;
use Interpro\QS\Model\Block;
use Interpro\QS\Model\Group;
use Interpro\QS\Model\Ref;

class Initializer implements AInitializer
{
    private $refConsistMediator;
    private $initMediator;

    public function __construct(RefConsistMediator $refConsistMediator, InitMediator $initMediator)
    {
        $this->initMediator = $initMediator;
        $this->refConsistMediator = $refConsistMediator;
    }

    /**
     * @return string
     */
    public function getFamily()
    {
        return 'qs';
    }

    private function slugExist($slug)
    {
        $collection = Group::where('slug', $slug)->get();

        return !$collection->isEmpty();
    }

    private function refExist(AType $type, $id)
    {
        $ref = new ARef($type, $id);

        return $this->refConsistMediator->exist($ref);
    }

    /**
     * @param \Interpro\Core\Contracts\Taxonomy\Types\AType $type
     * @param array $defaults
     *
     * @return \Interpro\Core\Contracts\Ref\ARef
     */
    public function init(AType $type, array $defaults = [])
    {
        $type_name = $type->getName();

        $self_fields = ['id', 'name', 'slug', 'title', 'sorter', 'show'];

        //[[[
        DB::beginTransaction();

        if($type->getRank() === TypeRank::BLOCK)
        {
            $model = Block::where('name', '=', $type_name)->first();
            if(!$model)
            {
                $model = new Block();
                $model->name = $type_name;

                if(array_key_exists('show', $defaults))
                {
                    $model->show = (bool) $defaults['show'];
                }
                else
                {
                    $model->show = true;
                }

                if(array_key_exists('title', $defaults))
                {
                    $model->title = $defaults['title'];
                }
                else
                {
                    $model->title = 'Блок '.$type_name;
                }

                $model->save();
            }

            $id = 0;
        }
        elseif($type->getRank() === TypeRank::GROUP)
        {
            $model = new Group();
            $model->name = $type_name;

            if(array_key_exists('show', $defaults))
            {
                $model->show = (bool) $defaults['show'];
            }
            else
            {
                $model->show = true;
            }

            if(array_key_exists('title', $defaults))
            {
                $model->title = $defaults['title'];
            }
            else
            {
                $model->title = 'Блок '.$type_name;
            }

            if(array_key_exists('slug', $defaults))
            {
                if($this->slugExist($defaults['slug']))
                {
                    throw new QSException('Значение slug '.$defaults['slug'].' для типа '.$type_name.' уже занято!');
                }

                $model->slug = $defaults['slug'];
            }
            else
            {
                $model->slug = 'new'.time();
            }

            if(array_key_exists('sorter', $defaults))
            {
                $model->sorter = $defaults['sorter'];
            }
            else
            {
                $model->sorter = 999;
            }

            $model->save();

            $id = $model->id;
        }
        else
        {
            throw new QSException('При инициализации передан тип с рангом отличным от блока и группы: '.$type->getRank().'!');
        }

        //----------------------------------------------------------------------
        $refs = $type->getRefs();

        foreach($refs as $ref_name => $ref)
        {
            $refType = $ref->getFieldType();

            $ref_type_name = $refType->getName();

            if(array_key_exists($ref_name, $defaults))
            {
                $ref_entity_id = (int) $defaults[$ref_name];
            }
            else
            {
                $ref_entity_id = 0;
            }

            if($refType->getRank() === TypeRank::GROUP and $ref_entity_id === 0)
            {
                $throwexc = false;
            }
            else
            {
                $throwexc = !$this->refExist($refType, $ref_entity_id);
            }

            if ($throwexc)
            {
                throw new QSException('Сущность по ссылке отсутствует: '.$ref_type_name.'('.$ref_entity_id.')!');
            }

            $ref = Ref::firstOrNew(['entity_name' => $type_name, 'entity_id' => $id, 'name' => $ref_name, 'ref_entity_name' => $ref_type_name]);

            $ref->ref_entity_id = $ref_entity_id;

            $ref->save();
        }


        $Aref = new ARef($type, $id);
        //----------------------------------------------------------------------
        $owns = $type->getOwns();

        foreach($owns as $own_name => $own)
        {
            if(in_array($own_name, $self_fields))
            {
                continue;
            }

            $family = $own->getFieldTypeFamily();
            $mode = $own->getMode();

            if(array_key_exists($own_name, $defaults))
            {
                $value = $defaults[$own_name];
            }
            else
            {
                $value = null;
            }

            if($mode === TypeMode::MODE_B)
            {
                $initializer = $this->initMediator->getBInitializer($family);
                $initializer->init($Aref, $own, $value);
            }
            elseif($mode === TypeMode::MODE_C)
            {
                $initializer = $this->initMediator->getCInitializer($family);
                $initializer->init($Aref, $own, $value);
            }
        }

        DB::commit();
        //]]]

        return $Aref;
    }
}
