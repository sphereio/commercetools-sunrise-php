<?php
/**
 * @author jayS-de <jens.schulze@commercetools.de>
 */

namespace Commercetools\Sunrise\AppBundle\Repository;

use Commercetools\Core\Request\Categories\CategoryQueryRequest;
use Commercetools\Sunrise\AppBundle\Model\Repository;

class CategoryRepository extends Repository
{
    const NAME = 'categories';

    public function getCategories()
    {
        $cacheKey = 'categories';
        $categoriesRequest = CategoryQueryRequest::of();
        return $this->retrieveAll(static::NAME, $cacheKey, $categoriesRequest);
    }
}
