<?php

declare(strict_types=1);

namespace Terminal42\ChangeLanguage;

use Contao\Date;
use Contao\Model\Collection;
use Contao\PageModel;
use Contao\System;

class PageFinder
{
    /**
     * @var array
     */
    private $websiteRootPageIds;

    /**
     * @var bool
     */
    private $negateWebsiteRootsSelection;

    /**
     * Constructor.
     *
     * @param array $websiteRootPageIds          limits result to given root pages
     * @param bool  $negateWebsiteRootsSelection if true, negates condition so all but the given $websiteRootPageIds are found
     */
    public function __construct(array $websiteRootPageIds = [], $negateWebsiteRootsSelection = false)
    {
        //fix for: Warning: count(): Parameter must be an array or an object that implements Countable in php 7.2
        $this->websiteRootPageIds = [];

        foreach(array_unique($websiteRootPageIds) as $id) {
            $this->websiteRootPageIds[] = intval($id);
        }

        $this->negateWebsiteRootsSelection = (bool)$negateWebsiteRootsSelection;
    }

    /**
     * @param PageModel $page
     * @param bool      $skipCurrent
     * @param bool      $publishedOnly
     *
     * @return array<PageModel>
     */
    public function findRootPagesForPage(PageModel $page, bool $skipCurrent = false, bool $publishedOnly = true): array
    {
        $page->loadDetails();
        $t = $page::getTable();

        $columns = [
            "$t.type='root'",
            "(
                $t.dns=?
                OR $t.dns IN (
                    SELECT dns
                    FROM tl_page
                    WHERE type='root' AND fallback='1' AND id = (
                        SELECT languageRoot FROM tl_page WHERE type='root' AND fallback='1' AND dns=? LIMIT 1
                    )
                )
                OR $t.dns IN (
                    SELECT dns
                    FROM tl_page
                    WHERE type='root' AND fallback='1' AND languageRoot = (
                        SELECT id FROM tl_page WHERE type='root' AND fallback='1' AND dns=? LIMIT 1
                    )
                )
                OR $t.dns IN (
                    SELECT dns
                    FROM tl_page
                    WHERE type='root' AND fallback='1' AND languageRoot != 0 AND languageRoot = (
                        SELECT languageRoot FROM tl_page WHERE type='root' AND fallback='1' AND dns=? LIMIT 1
                    )
                )
            )",
        ];

        $values = [$page->domain, $page->domain, $page->domain, $page->domain];

        if ($skipCurrent) {
            $columns[] = "$t.id!=?";
            $values[] = $page->rootId;
        }

        if ($publishedOnly) {
            $this->addPublishingConditions($columns, $t);
        }

        $this->addWebsiteRootPageIdsCondition($columns, $values, $t, $page);

        return $this->findPages($columns, $values, ['order' => 'sorting']);
    }

    /**
     * Finds the root page of fallback language for the given page.
     */
    public function findMasterRootForPage(PageModel $page): ?PageModel
    {
        $page->loadDetails();
        $t = $page::getTable();

        $columns = [
            "$t.type='root'",
            "$t.fallback='1'",
            "$t.languageRoot=0",
            "(
                $t.dns=?
                OR $t.dns IN (SELECT dns FROM tl_page WHERE type='root' AND fallback='1' AND id IN (SELECT languageRoot FROM tl_page WHERE type='root' AND fallback='1' AND dns=?))
                OR $t.dns IN (SELECT dns FROM tl_page WHERE type='root' AND fallback='1' AND languageRoot IN (SELECT id FROM tl_page WHERE type='root' AND fallback='1' AND dns=?))
            )",
        ];

        $values = [$page->domain, $page->domain, $page->domain];

        $this->addWebsiteRootPageIdsCondition($columns, $values, $t, $page);

        return PageModel::findOneBy($columns, $values);
    }

    /**
     * @return array<PageModel>
     */
    public function findAssociatedForPage(PageModel $page, bool $skipCurrent = false, ?array $rootPages = null, bool $publishedOnly = true): array
    {
        if ('root' === $page->type) {
            return $this->findRootPagesForPage($page, $skipCurrent, $publishedOnly);
        }

        if (null === $rootPages) {
            $rootPages = $this->findRootPagesForPage($page, $skipCurrent, $publishedOnly);
        }

        $page->loadDetails();
        $t = $page::getTable();

        if ($page->rootIsFallback && null !== ($root = PageModel::findByPk($page->rootId)) && !$root->languageRoot) {
            $values = [$page->id, $page->id];
        } elseif (!$page->languageMain) {
            return $skipCurrent ? [] : [$page];
        } else {
            $values = [$page->languageMain, $page->languageMain];
        }

        $columns = ["($t.id=? OR $t.languageMain=?)"];

        if ($skipCurrent) {
            $columns[] = "$t.id!=?";
            $values[] = $page->id;
        }

        if ($publishedOnly) {
            $this->addPublishingConditions($columns, $t);
        }

        return array_filter(
            $this->findPages($columns, $values),
            static function (PageModel $page) use ($rootPages) {
                $page->loadDetails();

                return \array_key_exists($page->rootId, $rootPages);
            }
        );
    }

    public function findAssociatedForLanguage(PageModel $page, string $language): PageModel
    {
        $language = Language::toLocaleID($language);
        $associated = $this->findAssociatedForPage($page);

        foreach ($associated as $model) {
            $model->loadDetails();

            if (Language::toLocaleID($model->language) === $language) {
                return $model;
            }
        }

        // No page found, find for parent
        return $this->findAssociatedParentForLanguage($page, $language);
    }

    public function findAssociatedInMaster(PageModel $page): ?PageModel
    {
        $page->loadDetails();
        $masterRoot = $this->findMasterRootForPage($page);

        if ($masterRoot->id === $page->rootId) {
            return null;
        }

        $associated = $this->findAssociatedForPage($page);

        foreach ($associated as $model) {
            $model->loadDetails();

            if ($model->rootId === $masterRoot->id) {
                return $model;
            }
        }

        return null;
    }

    /**
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function findAssociatedParentForLanguage(PageModel $page, string $language): PageModel
    {
        // Stop loop if we're at the top
        if (0 === $page->pid || 'root' === $page->type) {
            $rootPages = $this->findRootPagesForPage($page);

            foreach ($rootPages as $model) {
                if (Language::toLocaleID($model->language) === $language) {
                    return $model;
                }
            }

            throw new \InvalidArgumentException(sprintf('There\'s no language "%s" related to root page ID "%s"', $language, $page->id));
        }

        $parent = PageModel::findPublishedById($page->pid);

        if (!$parent instanceof PageModel) {
            throw new \RuntimeException(sprintf('Parent page for page ID "%s" not found', $page->id));
        }

        return $this->findAssociatedForLanguage($parent, $language);
    }

    private function addPublishingConditions(array &$columns, string $table): void
    {
        if (!System::getContainer()->get('contao.security.token_checker')->isPreviewMode()) {
            $start = Date::floorToMinute();
            $stop = $start + 60;

            $columns[] = "$table.published='1'";
            $columns[] = "($table.start='' OR $table.start<$start)";
            $columns[] = "($table.stop='' OR $table.stop>$stop)";
        }
    }

    /**
     * @param array  $columns
     * @param array  $values
     * @param string $table
     */
    private function addWebsiteRootPageIdsCondition(array &$columns, array &$values, $table, PageModel $page)
    {
        if (count($this->websiteRootPageIds)) {
            $websiteRootPageIds = $this->addCurrentPageRootToSelection($page, ...$this->websiteRootPageIds);

            /**
             * this will generate a comma separated string of placeholders
             * example: if there are 5 entries in websiteRootPageIds it will generate the following string: ?,?,?,?,?
             */
            $placeholders = (implode(',', array_pad([], count($websiteRootPageIds), '?')));

            $negation = ($this->negateWebsiteRootsSelection)? 'NOT' : '';

            $columns[] = "$table.id ${negation} IN(${placeholders})";
            $values = array_merge($values, $websiteRootPageIds);
        }
    }

    /**
     * @param array $columns
     * @param array $values
     * @param array $options
     *
     * @return array<PageModel>
     */
    private function findPages(array $columns, array $values, array $options = []): array
    {
        /** @var Collection $collection */
        $collection = PageModel::findBy($columns, $values, $options);

        if (!$collection instanceof Collection) {
            return [];
        }

        $models = [];

        foreach ($collection as $model) {
            $models[$model->id] = $model;
        }

        return $models;
    }

    private function addCurrentPageRootToSelection(PageModel $page, int ...$websiteRootPageIds)
    {
        /**
         * usecase: you want to show all websiteRootPageIds which are NOT configured in the module config
         * if the rootId of the current page is part of the list, we need to remove it - if its not removed then the
         * active page will not be shown in the list
         */
        if ($this->negateWebsiteRootsSelection && in_array($page->rootId, $websiteRootPageIds)) {
            $key = array_search($page->rootId, $websiteRootPageIds);

            if ($key !== false) {
                unset($websiteRootPageIds[$key]);
            }
        }

        /**
         * usecase: you want to show all websiteRootPageIds which are configured in the module config
         * if the rootId of the current page is NOT part of the list, we need to add it - if its not added then the
         * active page will not be shown in the list
         */
        if (!$this->negateWebsiteRootsSelection && !in_array($page->rootId, $websiteRootPageIds)) {
            $websiteRootPageIds[] = $page->rootId;
        }

        return $websiteRootPageIds;
    }
}
