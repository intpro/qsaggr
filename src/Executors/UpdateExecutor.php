<?php

namespace Interpro\QS\Executors;

use Illuminate\Support\Facades\DB;
use Interpro\Core\Contracts\Executor\AUpdateExecutor;
use Interpro\Core\Contracts\Mediator\RefConsistMediator;
use Interpro\Core\Contracts\Mediator\UpdateMediator;
use Interpro\Core\Contracts\Ref\ARef as ARefInterface;
use Interpro\Core\Contracts\Taxonomy\Types\AType;
use Interpro\Core\Taxonomy\Enum\TypeMode;
use Interpro\Core\Taxonomy\Enum\TypeRank;
use Interpro\QS\Exception\QSException;
use Interpro\QS\Model\Block;
use Interpro\QS\Model\Group;
use Interpro\QS\Model\Ref;
use Interpro\Core\Ref\ARef;

class UpdateExecutor implements AUpdateExecutor
{
    private $refConsistMediator;
    private $updateMediator;

    public function __construct(RefConsistMediator $refConsistMediator, UpdateMediator $updateMediator)
    {
        $this->updateMediator = $updateMediator;
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
     * @param \Interpro\Core\Contracts\Ref\ARef $ref
     * @param array $values
     *
     * @return void
     */
    public function update(ARefInterface $Aref, array $values)
    {
        $type = $Aref->getType();
        $type_name = $type->getName();

        $id = $Aref->getId();

        $type_rank = $type->getRank();

        $self_fields = ['slug', 'title','sorter','show'];

        //[[[
        DB::beginTransaction();

        if($type_rank === TypeRank::BLOCK)
        {
            $model = Block::find($type_name);
            if(!$model)
            {
                throw new QSException('Не найден блок по имени '.$type_name.'!');
            }

            if(array_key_exists('show', $values))
            {
                $model->show = (bool) $values['show'];
            }

            if(array_key_exists('title', $values))
            {
                $model->title = $values['title'];
            }

            $model->save();
        }
        elseif($type_rank === TypeRank::GROUP)
        {
            $model = Group::find($id);
            if(!$model)
            {
                throw new QSException('Не найден элемент группы '.$type_name.'('.$id.')!');
            }

            if(array_key_exists('show', $values))
            {
                $model->show = (bool) $values['show'];
            }

            if(array_key_exists('title', $values))
            {
                $model->title = $values['title'];
            }

            if(array_key_exists('slug', $values))
            {
                if($values['slug'] !== $model->slug and $this->slugExist($values['slug']))
                {
                    throw new QSException('Значение slug '.$values['slug'].' для типа '.$type_name.' уже занято!');
                }

                $model->slug = $values['slug'];
            }

            if(array_key_exists('sorter', $values))
            {
                $model->sorter = $values['sorter'];
            }

            $model->save();
        }
        else
        {
            throw new QSException('При сохранении изменений передан тип с рангом отличным от блока и группы: '.$type->getRank().'!');
        }

        $refs = $type->getRefs();

        foreach($refs as $ref_name => $ref)
        {
            $refType = $ref->getFieldType();

            $ref_type_name = $refType->getName();

            if(array_key_exists($ref_name, $values))
            {
                $ref_entity_id = (int) $values[$ref_name];

                if (!$this->refExist($refType, $ref_entity_id))
                {
                    throw new QSException('Сущность по ссылке отсутствует: '.$ref_type_name.'('.$ref_entity_id.')!');
                }

                $refModel = Ref::firstOrNew(['entity_name' => $type_name, 'entity_id' => $id, 'name' => $ref_name, 'ref_entity_name' => $ref_type_name]);
                $refModel->ref_entity_id = $ref_entity_id;
                $refModel->save();
            }
        }


        $owns = $type->getOwns();

        foreach($owns as $own_name => $own)
        {
            if(in_array($own_name, $self_fields))
            {
                continue;
            }

            $family = $own->getFieldTypeFamily();
            $mode = $own->getMode();

            if(array_key_exists($own_name, $values))
            {
                $value = $values[$own_name];

                if($mode === TypeMode::MODE_B)
                {
                    $updater = $this->updateMediator->getBUpdateExecutor($family);
                    $updater->update($Aref, $own, $value);
                }
                elseif($mode === TypeMode::MODE_C)
                {
                    $updater = $this->updateMediator->getCUpdateExecutor($family);
                    $updater->update($Aref, $own, $value);
                }
            }
        }

        DB::commit();
        //]]]
    }
}
