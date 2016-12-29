<?php

namespace Interpro\QS\Db;

use Interpro\Core\Contracts\Ref\ARef;
use Interpro\Core\Contracts\Taxonomy\Fields\OwnField;
use Interpro\Core\Contracts\Taxonomy\Fields\RefField;
use Interpro\Core\Taxonomy\Enum\TypeMode;
use Interpro\Core\Taxonomy\Enum\TypeRank;
use Interpro\Extractor\Contracts\Collections\MapBCollection;
use Interpro\Extractor\Contracts\Collections\MapCCollection;
use Interpro\Extractor\Contracts\Creation\CItemBuilder;
use Interpro\Extractor\Contracts\Creation\CollectionFactory;
use Interpro\Extractor\Contracts\Db\AMapper;
use Interpro\Extractor\Contracts\Db\MappersMediator;
use Interpro\Extractor\Contracts\Selection\Tuner;
use Interpro\Extractor\Fields\AARefField;
use Interpro\Extractor\Items\AItem;
use Interpro\Extractor\Contracts\Selection\SelectionUnit;
use Interpro\Extractor\Fields\ABOwnField;
use Interpro\Extractor\Fields\ACOwnField;
use Interpro\QS\Creation\QSItemFactory;
use Interpro\QS\Exception\QSException;

class QSAMapper implements AMapper
{
    private $factory;
    private $collectionFactory;
    private $cItemBuilder;
    private $mappersMediator;
    private $qsQuerier;
    private $tuner;
    private $items = [];
    private $collections = [];
    private $local_fields = ['id', 'name', 'slug', 'title', 'sorter', 'show'];

    public function __construct(QSItemFactory $factory, CollectionFactory $collectionFactory, CItemBuilder $cItemBuilder, MappersMediator $mappersMediator, QSQuerier $qsQuerier, Tuner $tuner)
    {
        $this->factory           = $factory;
        $this->collectionFactory = $collectionFactory;
        $this->cItemBuilder      = $cItemBuilder;
        $this->mappersMediator   = $mappersMediator;
        $this->qsQuerier         = $qsQuerier;
        $this->tuner             = $tuner;
    }

    private function local($field_name)
    {
        return in_array($field_name, $this->local_fields);
    }

    /**
     * @return void
     */
    public function reset()
    {
        $this->items = [];
        $this->collections = [];
    }

    /**
     * @return string
     */
    public function getFamily()
    {
        return 'qs';
    }

    private function createRefField(AItem $owner, RefField $refMeta, & $refs_array)
    {
        $fieldType = $refMeta->getFieldType();

        $field_name = $refMeta->getName();
        $key = $field_name.'_'.$owner->getSelfRef()->getId();

        if(array_key_exists($key, $refs_array))
        {
            $id = $refs_array[$key]['ref_entity_id'];
        }
        else
        {
            $id = 0;
        }

        $ref = new \Interpro\Core\Ref\ARef($fieldType, $id);

        $field_type_family = $fieldType->getFamily();

        $mapper = $this->mappersMediator->getAMapper($field_type_family);

        $newField = new AARefField($owner, $refMeta, $ref, $mapper);

        return $newField;
    }

    /**
     * @param AItem $owner
     * @param OwnField $ownMeta
     * @param array & $result
     *
     * @return \Interpro\Extractor\Fields\ACOwnField
     */
    private function createLocalCField(AItem $owner, OwnField $ownMeta, & $result)
    {
        $field_name = $ownMeta->getName();
        $fieldType = $ownMeta->getFieldType();

        if(array_key_exists($field_name, $result))
        {
            $scalarItem = $this->cItemBuilder->create($fieldType, $result[$field_name]);
        }
        else
        {
            $scalarItem = $this->cItemBuilder->createCap($fieldType);
        }

        $newField = new ACOwnField($owner, $ownMeta);
        $newField->setItem($scalarItem);

        return $newField;
    }

    private function createExternalBFieldByRef(AItem $owner, OwnField $ownMeta, MapBCollection $map)
    {
        $field_name = $ownMeta->getName();

        $ref = $owner->getSelfRef();

        $fieldItem = $map->getItem($ref, $field_name);
        $newField = new ABOwnField($owner, $ownMeta);
        $newField->setItem($fieldItem);

        return $newField;
    }

    private function createExternalCFieldByRef(AItem $owner, OwnField $ownMeta, MapCCollection $map)
    {
        $field_name = $ownMeta->getName();

        $ref = $owner->getSelfRef();

        $scalarItem = $map->getItem($ref, $field_name);
        $newField = new ACOwnField($owner, $ownMeta);
        $newField->setItem($scalarItem);

        return $newField;
    }

    /**
     * @param \Interpro\Core\Contracts\Ref\ARef $ref
     * @param bool $asUnitMember
     *
     * @return \Interpro\Extractor\Contracts\Items\AItem
     */
    public function getByRef(ARef $ref, $asUnitMember = false)
    {
        $type  = $ref->getType();
        $rank = $type->getRank();
        $type_name = $type->getName();
        $id = $ref->getId();

        if($rank === TypeRank::GROUP and $asUnitMember)
        {
            $selectionUnit = $this->tuner->getSelection($type_name, 'group');

            $collection = $this->select($selectionUnit);

            return $collection->getItem($id);
        }

        $id = $ref->getId();

        $result = $this->qsQuerier->selectByRef($ref);
        $result = $result->get();

        if(count($result) === 0)
        {
            throw new QSException('При получении элемента не найдены данные в таблице '.$type_name.': по ссылке '.$ref.'!');
        }

        $refs_array = $this->qsQuerier->getRefValues($type_name, $id);


        $item = $this->factory->create($ref);

        //====================================================поля
        $ownsMetaCollection = $type->getOwns();

        foreach($ownsMetaCollection as $ownMeta)
        {
            $fieldType = $ownMeta->getFieldType();
            $fieldMode = $ownMeta->getMode();

            $field_name = $ownMeta->getName();

            if($this->local($field_name)) //все поля локального хранения - С типы
            {
                $newField = $this->createLocalCField($item, $ownMeta, $result[0]);
            }
            else
            {
                if($fieldMode === TypeMode::MODE_B)
                {
                    $mapper = $this->mappersMediator->getBMapper($fieldType->getFamily());
                    $map = $mapper->getByRef($ref);
                    $newField = $this->createExternalBFieldByRef($item, $ownMeta, $map);
                }
                elseif($fieldMode === TypeMode::MODE_C)
                {
                    $mapper = $this->mappersMediator->getCMapper($fieldType->getFamily());
                    $map = $mapper->getByRef($ref);
                    $newField = $this->createExternalCFieldByRef($item, $ownMeta, $map);
                }
                else
                {
                    throw new QSException('В типе '.$type_name.' обнаружено поле-собственность типа отличного от В или С: '.$field_name.'('.$fieldMode.')!');
                }
            }

            $item->setOwn($newField);
            $item->setField($newField);
        }

        //====================================================ссылки
        $refsMetaCollection = $type->getRefs();

        foreach($refsMetaCollection as $refMeta)
        {
            $newField = $this->createRefField($item, $refMeta, $refs_array);

            $item->setRef($newField);
            $item->setField($newField);
        }

        return $item;
    }

    /**
     * @param \Interpro\Extractor\Contracts\Selection\SelectionUnit $selectionUnit
     *
     * @return \Interpro\Extractor\Contracts\Collections\MapGroupCollection
     */
    public function select(SelectionUnit $selectionUnit)
    {
        $key = 'number_'.$selectionUnit->getNumber();

        if(array_key_exists($key, $this->collections))
        {
            return $this->collections[$key];
        }

        //====================================================
        $type = $selectionUnit->getType();
        $type_name = $type->getName();

        $mapCollection = $this->collectionFactory->createMapGroupCollection($type);

        $qb = $this->qsQuerier->selectByUnit($selectionUnit);

        $result_array = $qb->get();

        $id_set = array_column($result_array, 'id');

        $selectionUnit->addId($id_set);
        $selectionUnit->complete();

        $ownsMetaCollection = $type->getOwns();
        $refsMetaCollection = $type->getRefs();

        $refs_array = $this->qsQuerier->getRefValues($type_name, $id_set);

        foreach($result_array as $item_result)
        {
            $ref = new \Interpro\Core\Ref\ARef($type, (int)$item_result['id']);

            $item = $this->factory->create($ref);

            //====================================================поля
            foreach($ownsMetaCollection as $ownMeta)
            {
                $fieldType = $ownMeta->getFieldType();
                $fieldMode = $ownMeta->getMode();

                $field_name = $ownMeta->getName();

                if($this->local($field_name)) //все поля локального хранения - С типы
                {
                    $newField = $this->createLocalCField($item, $ownMeta, $item_result);
                }
                else
                {
                    if($fieldMode === TypeMode::MODE_B)
                    {
                        $mapper = $this->mappersMediator->getBMapper($fieldType->getFamily());
                        $map = $mapper->select($selectionUnit);//Селект кэшируется в маппере полей, обращаемся каждий раз при поиске поля
                        $newField = $this->createExternalBFieldByRef($item, $ownMeta, $map);
                    }
                    elseif($fieldMode === TypeMode::MODE_C)
                    {
                        $mapper = $this->mappersMediator->getCMapper($fieldType->getFamily());
                        $map = $mapper->select($selectionUnit);//Селект кэшируется в маппере полей, обращаемся каждий раз при поиске поля
                        $newField = $this->createExternalCFieldByRef($item, $ownMeta, $map);
                    }
                    else
                    {
                        throw new QSException('В типе '.$type_name.' обнаружено поле-собственность типа отличного от В или С: '.$field_name.'('.$fieldMode.')!');
                    }
                }

                $item->setOwn($newField);
                $item->setField($newField);
            }

            //====================================================ссылки
            foreach($refsMetaCollection as $refMeta)
            {
                $newField = $this->createRefField($item, $refMeta, $refs_array);

                $item->setRef($newField);
                $item->setField($newField);
            }

            $mapCollection->addItem($item);
        }

        $this->collections[$key] = $mapCollection;

        return $mapCollection;
    }

    /**
     * @param \Interpro\Extractor\Contracts\Selection\SelectionUnit $selectionUnit
     *
     * @return int
     */
    public function count(SelectionUnit $selectionUnit)
    {
        $qb = $this->qsQuerier->selectByUnit($selectionUnit);

        return $qb->count();
    }

}
