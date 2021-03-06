<?php
/**
 * @author jayS-de <jens.schulze@commercetools.de>
 */

namespace Commercetools\Sunrise\AppBundle\Controller;

use Commercetools\Core\Cache\CacheAdapterInterface;
use Commercetools\Core\Model\Category\Category;
use Commercetools\Core\Model\Category\CategoryCollection;
use Commercetools\Core\Model\Product\Search\Facet;
use Commercetools\Core\Model\Product\FacetResultCollection;
use Commercetools\Core\Model\Product\Search\Filter;
use Commercetools\Core\Model\Product\ProductProjectionCollection;
use Commercetools\Core\Model\Product\Search\FilterRange;
use Commercetools\Core\Model\ProductType\AttributeDefinition;
use Commercetools\Core\Model\ProductType\LocalizedEnumType;
use Commercetools\Core\Model\ProductType\ProductTypeCollection;
use Commercetools\Core\Response\PagedSearchResponse;
use Commercetools\Sunrise\AppBundle\Model\Facet\FilterSubtree;
use Commercetools\Sunrise\AppBundle\Model\Facet\FilterSubtreeCollection;
use Commercetools\Sunrise\AppBundle\Model\View\CategoryEntry;
use Commercetools\Sunrise\AppBundle\Model\View\Entry;
use Commercetools\Sunrise\AppBundle\Model\View\LinkList;
use Commercetools\Sunrise\AppBundle\Model\View\ViewLink;
use Commercetools\Sunrise\AppBundle\Model\View\ProductModel;
use Commercetools\Sunrise\AppBundle\Model\ViewData;
use Commercetools\Sunrise\AppBundle\Model\ViewDataCollection;
use Commercetools\Symfony\CtpBundle\Model\FacetConfig;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CatalogController extends SunriseController
{
    const SLUG_SKU_SEPARATOR = '--';

    /**
     * @var FacetResultCollection
     */
    protected $facets;

    public function homeAction(Request $request)
    {
        $viewData = $this->getViewData('Sunrise - Home', $request);
        $viewData->content->banners = new ViewData();
        $viewData->content->banners->bannerOne = new ViewData();
        $viewData->content->banners->bannerOne->first = new ViewLink(
            $this->generateUrl('category', ['category' => 'accessories'])
        );
        $viewData->content->banners->bannerOne->second = new ViewLink(
            $this->generateUrl('category', ['category' => 'women'])
        );
        $viewData->content->banners->bannerTwo = new ViewData();
        $viewData->content->banners->bannerTwo->first = new ViewLink(
            $this->generateUrl('category', ['category' => 'men'])
        );
        $viewData->content->banners->bannerThree = new ViewData();
        $viewData->content->banners->bannerThree->first = new ViewLink(
            $this->generateUrl('category', ['category' => 'shoes'])
        );
        $viewData->content->banners->bannerThree->third = new ViewLink(
            $this->generateUrl('category', ['category' => 'accessories-women-sunglasses'])
        );
        $viewData->content->banners->bannerThree->fourth = new ViewLink(
            $this->generateUrl('category', ['category' => 'accessories-women-sunglasses'])
        );

        return $this->render('home.hbs', $viewData->toArray(), $this->getCachableResponse());
    }

    public function searchAction(Request $request)
    {
        $locale = $this->get('commercetools.locale.converter')->convert($request->getLocale());

        $products = $this->getProducts($request);
        $viewData = $this->getSearchViewData($request, $locale);

        $viewData->content->products = new ViewData();
        $viewData->content->products->list = new ViewDataCollection();

        foreach ($products as $key => $product) {
            $viewData->content->products->list->add(
                $this->getProductModel()->getProductOverviewData($product, $product->getMasterVariant(), $locale)
            );
        }

        return $this->render('pop.hbs', $viewData->toArray(), $this->getCachableResponse());
    }

    protected function getSearchViewData(Request $request, $locale)
    {
        $uri = new Uri($request->getRequestUri());
        $query = \GuzzleHttp\Psr7\parse_query($uri->getQuery());

        $viewData = $this->getViewData('Sunrise - ProductRepository Overview Page', $request);

        $viewData->content->filterProductsUrl = $this->generateUrl('pop');

        $category = $this->getCategory($request);
        if ($category) {
            $viewData->content->text = (string)$category->getName();
            $viewData->content->banner = $this->getBannerContent();
        }

        $viewData->content->displaySelector = $this->getDisplayContent($uri, $query, $this->getItemsPerPage($request));
        $viewData->content->sortSelector = $this->getSortData($uri, $query, $this->getSort($request, 'sunrise.products.sort'));
        $viewData->content->facets = $this->getFiltersData($locale, $uri, $category);

        $viewData->content->pagination = $this->pagination;

        return $viewData;
    }

    public function detailAction(Request $request)
    {
        $locale = $this->get('commercetools.locale.converter')->convert($request->getLocale());

        $slug = $request->get('slug');
        $sku = $request->get('sku');

        $viewData = $this->getViewData('Sunrise - ProductRepository Detail Page', $request);

        $country = \Locale::getRegion($locale);
        $currency = $this->config->get('currencies.'. $country);
        $product = $this->get('commercetools.repository.product')->getProductBySlug($slug, $locale, $currency, $country);
        $productData = $this->getProductModel()->getProductDetailData($product, $sku, $locale);
        $viewData->content->product = $productData;

        return $this->render('pdp.hbs', $viewData->toArray(), $this->getCachableResponse());
    }

    protected function getBannerContent()
    {
        $assetsCache = $this->get('app.assets.cache');
        $banner = new ViewData();
        $banner->description = "Lorem dolor deserunt debitis voluptatibus odio id animi voluptates alias eum adipisci laudantium iusto totam quibusdam modi quo! Consectetur.";
        $banner->imageMobile = $this->config->get('sunrise.assetsPath') . '/' . $assetsCache->getFile('img/banner_banner.jpg');
        $banner->imageDesktop = $this->config->get('sunrise.assetsPath') . '/' .  $assetsCache->getFile('img/banner_desktop.jpg');

        return $banner;
    }

    protected function getSortData(UriInterface $uri, $query, $currentSort)
    {
        $sortData = new ViewData();
        $sortData->key = static::SORT_ELEMENT;
        $sortData->list = new ViewDataCollection();

        foreach ($this->config->get('sunrise.products.sort') as $sortKey => $sort) {
            $entry = new ViewData();
            $query[static::SORT_ELEMENT] = $sortKey;
            $uri = $uri->withQuery(\GuzzleHttp\Psr7\build_query($query));
            $entry->uri = (string)$uri;
            $entry->value = $sortKey;
            $entry->label = $this->trans('sortSelector.' . $sort['formValue'], [], 'catalog');
            if ($currentSort == $sort) {
                $entry->selected = true;
            }
            $sortData->list->add($entry);
        }
        return $sortData;
    }

    protected function getDisplayContent(UriInterface $uri, $query, $currentCount)
    {
        $display = new ViewData();
        $display->key = static::ITEM_COUNT_ELEMENT;
        $display->list = new ViewDataCollection();

        foreach ($this->config->get('sunrise.itemsPerPage') as $count) {
            $entry = new ViewData();
            $query[static::ITEM_COUNT_ELEMENT] = $count;
            $uri = $uri->withQuery(\GuzzleHttp\Psr7\build_query($query));
            $entry->uri = (string)$uri;
            $entry->value = $count;
            $entry->label = $count;
            if ($currentCount == $count) {
                $entry->selected = true;
            }
            $display->list->add($entry);
        }

        return $display;
    }

    protected function getFacetDefinitions($facetDefinitions = [])
    {
        $facetDefinitions[] = Facet::ofName('categories.id')->setAlias('categories')->setValue(FilterSubtree::ofId('*'));

        return $facetDefinitions;
    }

    protected function getFiltersData($locale, UriInterface $searchUri, Category $category = null)
    {
        $filter = new LinkList();
        $filter->url = $searchUri->getPath();
        $filter->list = new ViewDataCollection();
        $facetConfigs = $this->get('commercetools.search')->getFacetConfigs();

        $queryParams = \GuzzleHttp\Psr7\parse_query($searchUri->getQuery());
        foreach ($facetConfigs as $facetName => $facetConfig) {
            $filter->list->add(
                $this->getFacet(
                    $locale,
                    $facetName,
                    $facetConfig,
                    $searchUri,
                    $facetName !== 'categories' ? $queryParams : $category
                )
            );
        }

        return $filter;
    }

    protected function getFacet($locale, $facetName, FacetConfig $facetConfig, UriInterface $searchUri, $queryParams)
    {
        $method = 'get' . ucfirst($facetConfig->getType()) . 'Facet';
        return $this->$method($locale, $facetName, $facetConfig, $searchUri, $queryParams);
    }

    protected function getTextFacet($locale, $facetName, FacetConfig $facetConfig, UriInterface $searchUri, $queryParams)
    {
        $facetData = new ViewData();
        $facetData->selectFacet = true;
        $facetData->facet = new ViewData();
        $facetData->facet->available = true;
        $facetData->facet->label = $this->trans('filters.' . $facetName, [], 'catalog');
        $facetData->facet->key = $facetName;

        $limitedOptions = new ViewDataCollection();

        foreach ($this->facets->getByName($facetName)->getTerms() as $term) {
            $facetEntry = new ViewData();
            $facetEntry->value = $term->getTerm();
            $facetEntry->label = $term->getTerm();
            $facetEntry->count = $term->getCount();
            $limitedOptions->add($facetEntry);
        }

        $facetData->facet->limitedOptions = $limitedOptions;

        return $facetData;
    }

    protected function getEnumFacet($locale, $facetName, FacetConfig $facetConfig, UriInterface $searchUri, $queryParams)
    {
        $attributeName = $facetConfig->getField();
        $cache = $this->get('commercetools.cache');
        $cacheKey = $facetName .'-facet-' . $locale;
        $typeData = $this->get('app.repository.product_type')->getTypes($locale);
        if (!$cache->hasItem($cacheKey)) {
            $facetValues = [];
            /**
             * @var ProductTypeCollection $typeData
             */
            foreach ($typeData as $productType) {
                /**
                 * @var AttributeDefinition $attribute
                 */
                $attribute = $productType->getAttributes()->getByName($attributeName);
                if (is_null($attribute)) {
                    continue;
                }
                /**
                 * @var LocalizedEnumType $attributeType
                 */
                $attributeType = $attribute->getType();
                $values = $attributeType->getValues();

                foreach ($values as $value) {
                    if (isset($facetValues[$value->getKey()])) {
                        continue;
                    }
                    $facetEntry = new Entry((string)$value->getLabel() ,$value->getKey());
                    $facetValues[$value->getKey()] = $facetEntry;
                }
            }
            $item = $cache->getItem($cacheKey)->set(serialize($facetValues));
            $cache->save($item);
        } else {
            $facetValues = unserialize($cache->getItem($cacheKey)->get());
        }

        $facetData = new ViewData();
        $facetData->displayList = ($facetConfig->getDisplay() == 'list');
        $facetData->selectFacet = true;
        $facetData->facet = new ViewData();
        if ($facetConfig->isMultiSelect() === true) {
            $facetData->facet->multiSelect = $facetConfig->isMultiSelect();
        }
        $facetData->facet->available = true;
        $facetData->facet->label = $this->trans('filters.' . $facetName, [], 'catalog');
        $facetData->facet->key = $facetName;

        $limitedOptions = new ViewDataCollection();

        $selectedValues = array_diff_key($queryParams, [$facetName => true]);

        $facetData->facet->clearUri = $searchUri->withQuery(\GuzzleHttp\Psr7\build_query($selectedValues));
        foreach ($this->facets->getByName($facetName)->getTerms() as $term) {
            $key = $term->getTerm();
            $facetEntry = $facetValues[$term->getTerm()];

            $facetSelection = isset($queryParams[$facetName]) ? $queryParams[$facetName] : [];
            if (!is_array($facetSelection)) {
                $facetSelection = [$facetSelection];
            }

            if (in_array($key, $facetSelection)) {
                $facetEntry->selected = true;
                $uriValues = array_merge($selectedValues, [$facetName => array_diff($facetSelection, [$key])]);
            } else {
                $uriValues = array_merge($selectedValues, [$facetName => array_merge($facetSelection, [$key])]);
            }

            $uri = $searchUri->withQuery(\GuzzleHttp\Psr7\build_query($uriValues));
            $facetEntry->uri = $uri;
            $facetEntry->count = $term->getCount();
            $limitedOptions->add($facetEntry);
        }
        $facetData->facet->limitedOptions = $limitedOptions;

        return $facetData;
    }

    protected function getCategoriesFacet($locale, $facetName, FacetConfig $facetConfig, UriInterface $uri, Category $selectedCategory = null)
    {
        $cache = $this->get('commercetools.cache');
        $categoryFacet = $this->facets->getByName('categories');

        /**
         * @var CategoryCollection $categoryData
         */
        $categoryData = $this->get('app.repository.category')->getCategories($locale);

        $cacheKey = 'category-facet-tree-' . $locale;
        if (!$cache->hasItem($cacheKey)) {
            $categoryTree = [];
            /**
             * @var Category $category
             */
            foreach ($categoryData as $category) {
                $categoryEntry = new CategoryEntry(
                    (string)$category->getName(),
                    $this->generateUrl('category', ['category' => (string)$category->getSlug()])
                );
                $categoryEntry->id = (string)$category->getId();
                $categoryEntry->count = 0;
                $ancestors = $category->getAncestors();
                $categoryEntry->ancestors = [];
                if (!is_null($ancestors)) {
                    foreach ($ancestors as $ancestor) {
                        $categoryEntry->ancestors[] = $ancestor->getId();
                    }
                }
                $categoryTree[$category->getId()] = $categoryEntry;
            }
            foreach ($categoryTree as $entry) {
                $children = array_keys($categoryData->getByParent($entry->id));
                if ($children) {
                    $entry->children = new ViewDataCollection();
                }
                foreach ($children as $id) {
                    $entry->children->add($categoryTree[$id], $id);
                }
            }
            $item = $cache->getItem($cacheKey)->set(serialize($categoryTree));
            $cache->save($item);
        } else {
            $categoryTree = unserialize($cache->getItem($cacheKey)->get());
        }

        foreach ($categoryFacet->getTerms() as $term) {
            $categoryId = $term->getTerm();
            $categoryEntry = $categoryTree[$categoryId];
            $categoryEntry->count = $term->getCount();
        }

        $limitedOptions = new ViewDataCollection();
        if (is_null($selectedCategory)) {
            $categories = $categoryData->getRoots();
            foreach ($categories as $category) {
                $entry = $categoryTree[$category->getId()];
                unset($entry->children);
                $limitedOptions->add($entry);
            }
        } else {
            $this->addToCollection(
                $selectedCategory->getId(),
                $limitedOptions,
                $categoryTree[$selectedCategory->getId()]->ancestors,
                $categoryTree
            );
        }

        $queryParams = \GuzzleHttp\Psr7\parse_query($uri->getQuery());
        $categories = new ViewData();
        $categories->hierarchicalSelectFacet = true;
        $categories->facet = new ViewData();
        $categories->facet->clearUri = $this->generateUrl('pop', $queryParams);
        $categories->facet->countHidden = false;
        $categories->facet->available = true;
        $categories->facet->label = $this->trans('filters.productType', [], 'catalog');
        $categories->facet->key = 'product-type';
        $categories->facet->limitedOptions = $limitedOptions;

        return $categories;
    }

    protected function addToCollection(
        $categoryId,
        ViewDataCollection $collection,
        $ancestors,
        $categoryTree
    ) {
        if (!empty($ancestors)) {
            $firstAncestor = array_shift($ancestors);
            $firstAncestorEntry = $categoryTree[$firstAncestor];
            if ($categoryId == $firstAncestorEntry->id) {
                $firstAncestorEntry->selected = true;
            }

            foreach ($firstAncestorEntry->children as $id => $entry) {
                if ($id != $categoryId && !in_array($id, $ancestors)) {
                    unset($entry->children);
                }
                if ($categoryId == $id) {
                    $entry->selected = true;
                }
            }
            $collection->add($firstAncestorEntry, $firstAncestor);
            $this->addToCollection($categoryId, $firstAncestorEntry->children, $ancestors, $categoryTree);
        } else {
            $categoryEntry = $categoryTree[$categoryId];
            if ($categoryId == $categoryEntry->id) {
                $categoryEntry->selected = true;
            }
            if (isset($categoryEntry->children)) {
                foreach ($categoryEntry->children as $entry) {
                    unset($entry->children);
                    if ($categoryId == $entry->id) {
                        $entry->selected = true;
                    }
                }
            }
            $collection->add($categoryEntry, $categoryId);
        }
    }


    protected function getCategoryFilter(Category $category = null)
    {
        $filters = [];

        if ($category instanceof Category) {
            $filters['filter.query'][] = Filter::ofName('categories.id')->setValue(
                FilterSubtreeCollection::of()->add(FilterSubtree::ofId($category->getId()))
            );
        }

        return $filters;
    }

    protected function getProducts(Request $request)
    {
        $locale = $this->get('commercetools.locale.converter')->convert($request->getLocale());
        $uri = new Uri($request->getRequestUri());
        $country = \Locale::getRegion($locale);
        $currency = $this->config->get('currencies.'. $country);
        $category = $this->getCategory($request);
        $itemsPerPage = $this->getItemsPerPage($request);
        $currentPage = $this->getCurrentPage($request);
        $sort = $this->getSort($request, 'sunrise.products.sort')['searchParam'];

        /**
         * @var ProductProjectionCollection $products
         * @var PagedSearchResponse $response
         */
        list($products, $facets, $offset, $total) = $this->get('commercetools.repository.product')->getProducts(
            $request->getLocale(),
            $itemsPerPage,
            $currentPage,
            $sort,
            $currency,
            $country,
            null,
            $uri,
            $this->getCategoryFilter($category)
        );

        $this->applyPagination($uri, $offset, $total, $itemsPerPage);
        $this->facets = $facets;

        return $products;
    }

    /**
     * @param Request $request
     * @return Category|null
     */
    protected function getCategory(Request $request)
    {
        $category = $request->get('category');

        if (!is_null($category)) {
            $locale = $this->get('commercetools.locale.converter')->convert($request->getLocale());
            /**
             * @var CategoryCollection $categories
             */
            $categories = $this->get('app.repository.category')->getCategories($locale);
            $category = $categories->getBySlug($category, $locale);
        }
        return $category;
    }

    protected function getProductModel()
    {
        /**
         * @var CacheAdapterInterface $cache
         */
        $cache = $this->get('commercetools.cache');
        $model = new ProductModel(
            $cache,
            $this->config,
            $this->get('app.repository.product_type'),
            $this->get('router')->getGenerator()
        );

        return $model;
    }

    protected function getCachableResponse()
    {
        $response = new Response();
        $response->setPublic();
        $response->setSharedMaxAge(60);

        return $response;
    }
}
