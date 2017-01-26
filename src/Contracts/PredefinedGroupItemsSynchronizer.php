<?php

namespace Interpro\QS\Contracts;

use Interpro\Core\Contracts\Taxonomy\Taxonomy;

interface PredefinedGroupItemsSynchronizer
{
    /**
     * @return void
     */
    public function sync(Taxonomy $taxonomy);
}
