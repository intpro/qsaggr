<?php

namespace Interpro\QS\Executors;

use Interpro\Core\Contracts\Executor\RefConsistExecutor as RefConsistExecutorInterface;
use Interpro\Core\Contracts\Ref\ARef;
use Interpro\Core\Taxonomy\Enum\TypeRank;
use Interpro\QS\Exception\QSException;
use Interpro\QS\Model\Block;
use Interpro\QS\Model\Group;
use Interpro\QS\Model\Ref;

class RefConsistExecutor implements RefConsistExecutorInterface
{
    /**
     * @return string
     */
    public function getFamily()
    {
        return 'qs';
    }

    /**
     * @param \Interpro\Core\Contracts\Ref\ARef $ref
     *
     * @return void
     */
    public function execute(ARef $ref)
    {
        $type      = $ref->getType();
        $type_name = $type->getName();
        $id        = $ref->getId();

        $collection = Ref::where('ref_entity_name', '=', $type_name)->where('ref_entity_id', '=', $id)->get();

        foreach($collection as $refModel)
        {
            //Обнуляем все ссылки, для блока это смысла не имеет, но блок может быть без записей в бд, просто как опорная сущность, так что ссылаться на него можно
            $refModel->ref_entity_id = 0;
            $refModel->save();
        }
    }

    /**
     * @param \Interpro\Core\Contracts\Ref\ARef $ref
     *
     * @return bool
     */
    public function exist(ARef $ref)
    {
        $type = $ref->getType();
        $type_name = $type->getName();

        $id = $ref->getId();

        $type_rank = $type->getRank();

        if($type_rank === TypeRank::BLOCK)
        {
            $collection = Block::where('name', $type_name)->get();
        }
        elseif($type_rank === TypeRank::GROUP)
        {
            $collection = Group::where('name', $type_name)->where('id', $id)->get();
        }
        else
        {
            throw new QSException('Не корректный ранг типа '.$type_name.' ссылки!');
        }

        return !$collection->isEmpty();
    }
}
