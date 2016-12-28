<?php

namespace Interpro\QS\Db;

use Illuminate\Support\Facades\DB;
use Interpro\Core\Contracts\Ref\ARef;
use Interpro\Core\Taxonomy\Enum\TypeRank;
use Interpro\Extractor\Contracts\Db\JoinMediator;
use Interpro\Extractor\Contracts\Selection\SelectionUnit;
use Interpro\Extractor\Db\QueryBuilder;
use Interpro\QS\Exception\QSException;

class QSQuerier
{
    private $joinMediator;

    public function __construct(JoinMediator $joinMediator)
    {
        $this->joinMediator = $joinMediator;
    }

    public function getRefValues($owner_name, $owner_id)
    {
        $refs_query = new QueryBuilder(DB::table('refs'));
        $refs_query->where('refs.entity_name', '=', $owner_name);

        if(is_array($owner_id))
        {
            $refs_query->whereIn('refs.entity_id', $owner_id);
        }
        else
        {
            $refs_query->where('refs.entity_id', '=', $owner_id);
        }

        $refs_result = $refs_query->get();
        $refs_values = [];

        foreach($refs_result as $ref_array)
        {
            $key = $ref_array['name'].'_'.$ref_array['entity_id'];
            $refs_values[$key] = $ref_array;
        }

        return $refs_values;
    }

    /**
     * @param \Interpro\Core\Contracts\Ref\ARef $ref
     *
     * @return \Interpro\Extractor\Db\QueryBuilder
     */
    public function selectByRef(ARef $ref)
    {
        $type  = $ref->getType();
        $type_name = $type->getName();
        $id = $ref->getId();

        if($type->getRank() === TypeRank::BLOCK)
        {
            $table = 'blocks';
        }
        elseif($type->getRank() === TypeRank::GROUP)
        {
            $table = 'groups';
        }
        else
        {
            throw new QSException('При получении данных элемента передана ссылка на тип с рангом отличным от блока и группы: '.$type->getRank().'!');
        }

        $query = new QueryBuilder(DB::table($table));
        $query->where($table.'.name', '=', $type_name);

        if($id > 0)
        {
            $query->where($table.'.id', '=', $id);
        }

        return $query;
    }

    /**
     * @param SelectionUnit $selectionUnit
     *
     * @return \Interpro\Extractor\Db\QueryBuilder
     * @throws \Interpro\QS\Exception\QSException
     */
    public function selectByUnit(SelectionUnit $selectionUnit)
    {
        $type  = $selectionUnit->getType();

        $entity_name    = $type->getName();
        $rank_name      = $type->getRank();

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

        //Группировка путей с общими отрезками
        //-------------------------------------------------------------
        $join_fields = $selectionUnit->getJoinFieldsPaths();

        $join_array = ['sub_levels' => [], 'full_field_names' => [], 'value_level' => false];//первый уровень - соединение с главным запросом + пути к полям по уровням

        foreach($join_fields as $field)
        {
            if ($field === 'title' or
                $field === 'slug' or
                $field === 'sorter' or
                $field === 'id' or
                $field === 'name' or
                $field === 'show')
            {
                continue;
            }

            $curr_level_array = & $join_array;

            $field_array     = explode('.', $field);
            $full_field_name = str_replace('.', '_', $field);

            $curr_level_array['full_field_names'][] = $full_field_name;

            foreach($field_array as $field_name)
            {
                if(!array_key_exists($field_name, $curr_level_array['sub_levels']))
                {
                    $curr_level_array['sub_levels'][$field_name] = ['sub_levels' => [], 'full_field_names' => [], 'value_level' => false];
                }

                $curr_level_array = &$curr_level_array['sub_levels'][$field_name];

                $curr_level_array['full_field_names'][] = $full_field_name;
            }

            $curr_level_array['value_level'] = true;//В конце всегда должно стоять поле скалярного типа
        }
        //-------------------------------------------------------------

        //В главном запросе можно пользоваться биндингом, а в подзапросах нельзя, так как порядок параметров будет сбиваться параметрами подзапросов
        $main_query = new QueryBuilder(DB::table($model_table));
        $main_query->where($model_table.'.name', '=', $entity_name);

        $get_fields = [
            $model_table.'.id',
            $model_table.'.name',
            $model_table.'.title',
            $model_table.'.slug',
            $model_table.'.sorter',
            $model_table.'.show'
        ];

        //Сначала подсоединяем все, кроме slug, title, sorter, show
        //$field - путь к полю разделенный точками
        foreach($join_array['sub_levels'] as $level0_field_name => $sub_array)
        {
            $Field = $type->getField($level0_field_name);
            $join_q = $this->joinMediator->externalJoin($Field, $sub_array);

            $main_query->leftJoin(DB::raw('('.$join_q->toSql().') AS '.$level0_field_name.'_table'), function($join) use ($level0_field_name, $model_table)
            {
                $join->on($model_table.'.name', '=', $level0_field_name.'_table.entity_name');
                $join->on($model_table.'.id',   '=', $level0_field_name.'_table.entity_id');
            });

            //$main_query->addBinding($join_q->getBindings());

            $get_fields = array_merge($get_fields, $sub_array['full_field_names']);
        }

        //Применим все параметры условия и сортировки выборки к запросу:
        $selectionUnit->apply($main_query);

        $main_query->select($get_fields);

        return $main_query;
    }


}
