<?php

namespace Interpro\QS;

use Interpro\Core\Contracts\Taxonomy\TypesForecastList;
use Interpro\Core\Taxonomy\Collections\ManifestsCollection;
use Interpro\Core\Taxonomy\Enum\TypeRank;
use Interpro\Core\Taxonomy\Manifests\ATypeManifest;
use Interpro\QS\Exception\QSException;

class ConfigInterpreter
{
    private $forecastList;

    public function __construct(TypesForecastList $forecastList)
    {
        $this->forecastList = $forecastList;
    }

    private function addFields(array & $fields_pattern, $config, $type_name, & $types)
    {
        if(array_key_exists($type_name, $config))
        {
            foreach($config[$type_name] as $field_name)
            {
                $fields_pattern[$field_name] = $type_name;
            }
            unset($types[$type_name]);
        }
    }

    private function addBlockPredefinedOwns(array & $fields_pattern)
    {
        $fields_pattern['name']  = 'string';
        $fields_pattern['title'] = 'string';
        $fields_pattern['show']  = 'bool';
    }

    private function addGroupPredefinedOwns(array & $fields_pattern)
    {
        $fields_pattern['id']     = 'int';
        $fields_pattern['name']   = 'string';
        $fields_pattern['slug']   = 'string';
        $fields_pattern['title']  = 'string';
        $fields_pattern['sorter'] = 'int';
        $fields_pattern['show']   = 'bool';
        $fields_pattern['updated_at'] = 'timestamp';
        $fields_pattern['created_at'] = 'timestamp';
    }

    private function addImageSet(& $patterns, $block_name, $superior_type, $set_name, $image_name)
    {
        $patterns[$set_name] = ['owns' => [], 'refs' => []];

        $this->addGroupPredefinedOwns($patterns[$set_name]['owns']);

        $patterns[$set_name]['rank'] = TypeRank::GROUP;
        $patterns[$set_name]['owns'][$image_name] = 'image';
        $patterns[$set_name]['refs']['block_name'] = $block_name;

        //Если это набор картинок группы
        if($superior_type)
        {
            $patterns[$set_name]['refs']['superior'] = $superior_type;
        }
    }

    private function scan(array & $config)
    {
        $patterns = [];

        foreach($config as $block_name => $block_config)
        {
            //Из $block_types будем вычитать типы которые совпали с зарегестрированными,
            //а которые останутся - не правильно заполненные названия типов в конфиге
            $block_types = array_flip(array_keys($block_config));
            if(in_array('groups', $block_types)) unset($block_types['groups']);
            if(in_array('imageset', $block_types)) unset($block_types['imageset']);
            //---------------------------------------------------------------------

            //********************Поля блока***************************************
            $patterns[$block_name] = ['owns' => [], 'refs' => []];
            $patterns[$block_name]['rank'] = TypeRank::BLOCK;

            //Поля предопределенные
            $this->addBlockPredefinedOwns($patterns[$block_name]['owns']);

            $c_names = $this->forecastList->getCTypeNames();
            $b_names = $this->forecastList->getBTypeNames();
            $a_names = $this->forecastList->getATypeNames();

            //Если есть поля какого-либо типа
            foreach($c_names as $type_name)
            {
                $this->addFields($patterns[$block_name]['owns'], $block_config, $type_name, $block_types);
            }

            //Если есть поля какого-либо агрегатного типа
            foreach($b_names as $type_name)
            {
                $this->addFields($patterns[$block_name]['owns'], $block_config, $type_name, $block_types);
            }

            //Если есть ссылки на какой-либо тип, вставляем
            foreach($a_names as $type_name)
            {
                $this->addFields($patterns[$block_name]['refs'], $block_config, $type_name, $block_types);
            }

            //наборы картинок
            if(array_key_exists('imageset', $block_config))
            {
                foreach($block_config['imageset'] as $set_name => $image_name)
                {
                    $this->addImageSet($patterns, $block_name, '', $set_name, $image_name);
                }
            }

            if(!empty($block_types))
            {
                throw new QSException('В настройке пакета qsagr содержаться незарегестрированные названия типов: '.implode(', ', array_flip($block_types)).'!');
            }
            //*********************************************************************

            //********************Группы*******************************************
            if(array_key_exists('groups', $block_config))
            {
                foreach($block_config['groups'] as $group_name => $group_config)
                {
                    $group_types = array_flip(array_keys($group_config));
                    if(in_array('groups', $group_types)) unset($group_types['groups']);
                    if(in_array('imageset', $group_types)) unset($group_types['imageset']);

                    $patterns[$group_name] = ['owns' => [], 'refs' => []];
                    $patterns[$group_name]['refs']['block_name'] = $block_name;
                    $patterns[$group_name]['rank'] = TypeRank::GROUP;

                    //Поля предопределенные
                    $this->addGroupPredefinedOwns($patterns[$group_name]['owns']);

                    //Если есть поля какого-либо типа
                    foreach($c_names as $type_name)
                    {
                        $this->addFields($patterns[$group_name]['owns'], $group_config, $type_name, $group_types);
                    }

                    //Если есть поля какого-либо агрегатного типа
                    foreach($b_names as $type_name)
                    {
                        $this->addFields($patterns[$group_name]['owns'], $group_config, $type_name, $group_types);
                    }

                    //Если есть ссылки на какой-либо тип, вставляем
                    foreach($a_names as $type_name)
                    {
                        $this->addFields($patterns[$group_name]['refs'], $group_config, $type_name, $group_types);
                    }

                    //наборы картинок
                    if(array_key_exists('imageset', $group_config))
                    {
                        foreach($group_config['imageset'] as $set_name => $image_name)
                        {
                            $this->addImageSet($patterns, $block_name, $group_name, $set_name, $image_name);
                        }
                    }

                    if(!empty($group_types))
                    {
                        throw new QSException('В настройке пакета qsagr содержаться незарегестрированные названия типов: '.implode(', ', array_flip($group_types)).'!');
                    }
                }
            }
            //*********************************************************************

        }

        return $patterns;
    }

    private function checkNames()
    {
        //Проверка на наличие обязательных скалярных типов для предопределённых полей

        $c_names = $this->forecastList->getCTypeNames();
        $b_names = $this->forecastList->getBTypeNames();

        if(!in_array('string', $c_names))
        {
            throw new QSException('Не зарегестрировано имя типа string, интерпретация предопределенных полей не возможна!');
        }

        if(!in_array('int', $c_names))
        {
            throw new QSException('Не зарегестрировано имя типа int, интерпретация предопределенных полей не возможна!');
        }

        if(!in_array('bool', $c_names))
        {
            throw new QSException('Не зарегестрировано имя типа bool, интерпретация предопределенных полей не возможна!');
        }

        if(!in_array('timestamp', $c_names))
        {
            throw new QSException('Не зарегестрировано имя типа timestamp (метка времени), интерпретация предопределенных полей не возможна!');
        }

        if(!in_array('image', $b_names))
        {
            throw new QSException('Не зарегестрировано имя типа image, интерпретация полей картинок в imageset не возможна!');
        }
    }

    private function createManifest($type_name, $typeRank, & $owns, & $refs)
    {
        return new ATypeManifest('qs', $type_name, $typeRank, $owns, $refs);
    }

    /**
     * @param array $config
     *
     * @return \Interpro\Core\Taxonomy\Collections\ManifestsCollection
     */
    public function interpretConfig(array $config)
    {
        $manifests = new ManifestsCollection();

        $this->checkNames();

        $patterns = $this->scan($config);

        foreach($patterns as $type_name => $pattern)
        {
            $man = $this->createManifest($type_name, $pattern['rank'], $pattern['owns'], $pattern['refs']);
            $manifests->addManifest($man);
        }

        return $manifests;
    }

}
