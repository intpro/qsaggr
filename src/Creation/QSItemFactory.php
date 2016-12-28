<?php

namespace Interpro\QS\Creation;

use Interpro\Core\Contracts\Ref\ARef;
use Interpro\Core\Taxonomy\Enum\TypeRank;
use Interpro\Extractor\Contracts\Creation\CollectionFactory;
use Interpro\Extractor\Items\BlockItem;
use Interpro\Extractor\Items\GroupItem;
use Interpro\QS\Exception\QSException;

class QSItemFactory
{
    private $collectionFactory;

    public function __construct(CollectionFactory $collectionFactory)
    {
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * @param \Interpro\Core\Contracts\Ref\ARef $ref
     *
     * @return \Interpro\Extractor\Items\AItem
     */
    public function create(ARef $ref)
    {
        //Здесь создется заготовка элемента, но полей, которые необходимо заполнить в элемент там, откуда вызвано создание заготовки
        $type = $ref->getType();

        $fields  = $this->collectionFactory->createFieldsCollection();
        $owns    = $this->collectionFactory->createOwnsCollection();
        $refs    = $this->collectionFactory->createRefsCollection();
        $subVars = $this->collectionFactory->createSubVarCollection($ref);

        if($type->getRank() === TypeRank::BLOCK)
        {
            $item = new BlockItem($ref, $fields, $owns, $refs, $subVars);
        }
        elseif($type->getRank() === TypeRank::GROUP)
        {
            $item = new GroupItem($ref, $fields, $owns, $refs, $subVars);
        }
        else
        {
            throw new QSException('При создании элемента передан тип с рангом отличным от блока и группы: '.$type->getRank().'!');
        }

        return $item;
    }

}
