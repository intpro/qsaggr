<?php

namespace Interpro\QS\Service;

use Interpro\Core\Contracts\Taxonomy\Taxonomy;
use Interpro\Core\Taxonomy\Enum\TypeRank;
use Interpro\QS\Model\Block;
use Interpro\QS\Model\Group;
use Interpro\Service\Contracts\Cleaner as CleanerInterface;
use Interpro\Service\Enum\Artefact;

class DbCleaner implements CleanerInterface
{
    private $taxonomy;
    private $consoleOutput;

    public function __construct(Taxonomy $taxonomy)
    {
        $this->taxonomy = $taxonomy;
        $this->consoleOutput = new \Symfony\Component\Console\Output\ConsoleOutput();
    }

    /**
     * @param callable $action
     *
     * @return bool
     */
    private function strategy(callable $action)
    {
        $report = false;

        $wehave = Block::all();

        $mustbe = $this->taxonomy->getBlocks();

        foreach($wehave as $block)
        {
            if(!$mustbe->exist($block->name))
            {
                $action(TypeRank::BLOCK, $block);
                $report = true;
            }
        }


        $wehave = Group::all();

        $mustbe = $this->taxonomy->getGroups();

        foreach($wehave as $group)
        {
            if(!$mustbe->exist($group->name))
            {
                $action(TypeRank::GROUP, $group);
                $report = true;
            }
        }

        return $report;
    }

    /**
     * @return bool
     */
    public function inspect()
    {
        $action = function($rank, $model)
        {
            if($rank === TypeRank::BLOCK)
            {
                $this->consoleOutput->writeln('QS: обнаружена запись блока '.$model->name.' не соответствующая таксономии.');
            }
            elseif($rank === TypeRank::GROUP)
            {
                $this->consoleOutput->writeln('QS: обнаружена запись группы '.$model->name.'('.$model->id.') не соответствующая таксономии.');
            }
        };

        $report = $this->strategy($action);

        return $report;
    }

    /**
     * @return void
     */
    public function clean()
    {
        $action = function($rank, $model)
        {
            $model->delete();

            if($rank === TypeRank::BLOCK)
            {
                $this->consoleOutput->writeln('QS: удалена запись блока '.$model->name.' не соответствующая таксономии.');
            }
            elseif($rank === TypeRank::GROUP)
            {
                $this->consoleOutput->writeln('QS: удалена запись группы '.$model->name.'('.$model->id.') не соответствующая таксономии.');
            }
        };

        $this->strategy($action);
    }

    /**
     * @return string
     */
    public function getArtefact()
    {
        return Artefact::DB_ROW;
    }

    /**
     * @return string
     */
    public function getFamily()
    {
        return 'qs';
    }
}
