<?php

namespace Interpro\QS\Creation;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Interpro\Core\Contracts\Taxonomy\Taxonomy;
use Interpro\Core\Ref\ARef;
use Interpro\QS\Contracts\PredefinedGroupItemsSynchronizer as PredefinedGroupItemsSynchronizerInterface;
use Interpro\QS\Executors\Initializer;
use Interpro\QS\Model\Group;

class PredefinedGroupItemsSynchronizer implements PredefinedGroupItemsSynchronizerInterface
{
    private $config;
    private $inConsole = false;
    private $initializer;

    public function __construct(Initializer $initializer, array $config)
    {
        $this->config = $config;
        $this->inConsole = App::runningInConsole();
        $this->initializer = $initializer;
    }

    private function getIdBySlug($name, $slug)
    {
        $item = Group::where('name', $name)->where('slug', $slug)->first();

        if(!$item)
        {
            return false;
        }
        else
        {
            return $item->id;
        }
    }

    /**
     * @return void
     */
    public function sync(Taxonomy $taxonomy)
    {
        //[[[
        DB::beginTransaction();

        $slug_refs = [];
        $slug_types = [];

        foreach($this->config as $group_name => $group_set)
        {
            if(!is_string($group_name))
            {

            }

            if(!is_array($group_set))
            {

            }

            $groupType = $taxonomy->getGroup($group_name);

            foreach($group_set as $slug => $refs)
            {
                if(!is_string($slug))
                {

                }

                if(!is_array($refs))
                {

                }

                $id = $this->getIdBySlug($group_name, $slug);

                if(!$id)
                {
                    $item_refs[]['self_ref'] = $this->initializer->init($groupType, ['slug' => $slug, 'predefined' => true]);
                }
                else
                {
                    $Aref = new ARef($groupType, $id);

                    $item_refs[]['self_ref'] = $Aref;
                }

                $item_refs[]['refs'] = $refs;

                $slug_types[$slug] = $group_name;
            }
        }

        foreach($slug_refs as $current)
        {
            $refs = $current['refs'];
            $self = $current['self_ref'];
        }

        DB::commit();
        //]]]
    }
}
