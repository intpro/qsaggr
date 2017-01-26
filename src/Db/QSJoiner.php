<?php

namespace Interpro\QS\Db;

use Illuminate\Support\Facades\DB;
use Interpro\Core\Contracts\Taxonomy\Fields\Field;
use Interpro\Core\Taxonomy\Enum\TypeRank;
use Interpro\Extractor\Contracts\Db\Joiner;
use Interpro\Extractor\Contracts\Db\JoinMediator;
use Interpro\Extractor\Db\QueryBuilder;
use Interpro\QS\Exception\QSException;

class QSJoiner implements Joiner
{
    private $joinMediator;

    public function __construct(JoinMediator $joinMediator)
    {
        $this->joinMediator = $joinMediator;
    }

    /**
     * @return string
     */
    public function getFamily()
    {
        return 'qs';
    }

    /**
     * @param \Interpro\Core\Contracts\Taxonomy\Fields\Field $field
     * @param array $join_array
     *
     * @return mixed
     */
    public function joinByField(Field $field, $join_array)
    {
        $fieldType = $field->getFieldType();
        $entity_name = $fieldType->getName();
        $field_name = $field->getName();

        $rank_name = $fieldType->getRank();

        if($rank_name === TypeRank::BLOCK)
        {
            $model_table = 'blocks';
        }
        elseif($rank_name === TypeRank::GROUP)
        {
            $model_table = 'groups';
        }
        else
        {
            throw new QSException('Не корректный ранг типа '.$entity_name.': '.$rank_name);
        }

        $join_q = new QueryBuilder(DB::table('refs'));
        $join_q->select(['refs.entity_name', 'refs.entity_id']);
        $join_q->whereRaw('refs.ref_entity_name = "'.$entity_name.'"');
        $join_q->whereRaw('refs.name = "'.$field_name.'"');

        //Если в продолжения пути нет, то $field_name и есть нужное поле
        foreach($join_array['sub_levels'] as $levelx_field_name => $sub_array)
        {
            if($levelx_field_name === 'id')
            {
                $join_q->addSelect('ref_entity_id as '.$sub_array['full_field_names'][0]);//Законцовка - в массиве только одно поле x_..x_id
            }
            else
            {
                if($levelx_field_name === 'slug' or $levelx_field_name === 'title' or $levelx_field_name === 'sorter' or $levelx_field_name === 'predefined' or $levelx_field_name === 'show' or $levelx_field_name === 'name')//Собственное поле
                {
                    $sub_q = new QueryBuilder(DB::table($model_table));

                    $sub_q->select([$model_table.'.name as entity_name', $model_table.'.id as entity_id', $model_table.'.'.$levelx_field_name.' as '.$join_array['full_field_names'][0]]);//Законцовка - в массиве только одно поле x_..x_id
                    $sub_q->whereRaw($model_table.'.name = "'.$entity_name.'"');
                }
                else//Присоединяемое поле
                {
                    $nextField = $fieldType->getField($levelx_field_name);
                    $sub_q = $this->joinMediator->externalJoin($nextField, $sub_array);
                }

                $join_q->leftJoin(DB::raw('('.$sub_q->toSql().') AS '.$levelx_field_name.'_table'), function($join) use ($levelx_field_name)
                {
                    $join->on('refs.ref_entity_name', '=', $levelx_field_name.'_table.entity_name');
                    $join->on('refs.ref_entity_id',   '=', $levelx_field_name.'_table.entity_id');
                });

                foreach($sub_array['full_field_names'] as $full_field_name)
                {
                    $join_q->addSelect($full_field_name);//Из $sub_q пришли поля с именами, добавляем все в текущую выборку
                }
            }
        }

        return $join_q;
    }

}
