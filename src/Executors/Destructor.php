<?php

namespace Interpro\QS\Executors;

use Illuminate\Support\Facades\DB;
use Interpro\Core\Contracts\Executor\ADestructor;
use Interpro\Core\Contracts\Mediator\DestructMediator;
use Interpro\Core\Contracts\Mediator\RefConsistMediator;
use Interpro\Core\Contracts\Ref\ARef;
use Interpro\Core\Taxonomy\Enum\TypeMode;
use Interpro\Core\Taxonomy\Enum\TypeRank;
use Interpro\QS\Exception\QSException;
use Interpro\QS\Model\Block;
use Interpro\QS\Model\Group;
use Interpro\QS\Model\Ref;

class Destructor implements ADestructor
{
    private $refConsistMediator;
    private $destructMediator;

    public function __construct(RefConsistMediator $refConsistMediator, DestructMediator $destructMediator)
    {
        $this->refConsistMediator = $refConsistMediator;
        $this->destructMediator = $destructMediator;
    }

    /**
     * @return string
     */
    public function getFamily()
    {
        return 'qs';
    }

    private function deleteOwns(ARef $ref)
    {
        $type = $ref->getType();

        //Внешние поля
        $families = [];
        $owns = $type->getOwns();

        foreach($owns as $ownField)
        {
            $own_f_f = $ownField->getFieldTypeFamily();

            if(in_array($own_f_f, $families))
            {
                continue;
            }

            $families[] = $own_f_f;

            if($ownField->getMode() === TypeMode::MODE_B)
            {
                $destructor = $this->destructMediator->getBDestructor($own_f_f);
                $destructor->delete($ref);
            }
            elseif($ownField->getMode() === TypeMode::MODE_C)
            {
                $destructor = $this->destructMediator->getCDestructor($own_f_f);
                $destructor->delete($ref);
            }
        }
    }

    /**
     * @param \Interpro\Core\Contracts\Ref\ARef $ref
     *
     * @return void
     */
    public function delete(ARef $ref)
    {
        $type = $ref->getType();
        $type_name = $type->getName();

        $id = $ref->getId();

        $type_rank = $type->getRank();

        //[[[
        DB::beginTransaction();

        //Удаление собственных ссылок на другие сущности
        Ref::where('entity_name', '=', $type_name)->where('entity_id', '=', $id)->delete();

        //Удаление внешних собственных полей
        $this->deleteOwns($ref);

        //Сообщение ссылающимся, об удалении сущности
        $this->refConsistMediator->notify($ref);

        if($type_rank === TypeRank::BLOCK)
        {
            Block::where('name', '=', $type_name)->delete();
        }
        elseif($type_rank === TypeRank::GROUP)
        {
            Group::where('name', '=', $type_name)->where('id', '=', $id)->delete();
        }
        else
        {
            throw new QSException('В деструкторе qs возможно удаление только сущностей ранга блок или группа, передано: '.$type->getName().'('.$type_rank.').');
        }

        DB::commit();
        //]]]
    }
}
