<?php

namespace Bolt\Extension\Europeana\StructureTree;

use Silex;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;
use Bolt;
use Bolt\StorageEvents;
use Bolt\Extensions\Snippets\Location as SnippetLocation;

class Extension extends \Bolt\BaseExtension
{
    public function initialize()
    {
        // listings

        // sitemap
        $this->app->match("/sitemap", array($this, 'sitemap'));
        $this->app->match("/sitemap.xml", array($this, 'xmlSitemap'));

        // slug listing
        $this->app->match("/{slug}", array($this, 'slugTreeRecord'))
            ->assert('slug', '[a-zA-Z0-9_\-]+[^(sitemap)^(search)]')
            ->bind('slugTreeRecord');

        // strucutureslug / slug listing
        // todo: except _profiler
        $this->app->match("/{structureSlugs}/{slug}", array($this, 'structureTreeRecord'))
            ->assert('structureSlugs', '(?!(async|_profiler)).*')
            ->assert('slug', '[a-zA-Z0-9_\-]+')
            ->bind('structureTreeRecord');


        // twig functions
        $this->addTwigFunction('structurelink', 'getStructureLink');
        $this->addTwigFunction('structurecontenttype', 'getContenttypeByStructure');
        $this->addTwigFunction('breadcrumb', 'breadCrumb');
        $this->addTwigFunction('subsite', 'subSite');
        $this->addTwigFunction('sitemap', 'renderSitemap');
        $this->addTwigFunction('sortRecords', 'sortObject');
        $this->addTwigFunction('getContenttype', 'getContenttype');


        // storage events
        // $this->app['dispatcher']->addListener(\Bolt\StorageEvents::POST_SAVE, array($this, 'updateStructureTaxonomy'));
        // $this->app['dispatcher']->addListener(\Bolt\StorageEvents::POST_DELETE, array($this, 'updateStructureTaxonomy'));

        $this->contenttypeslugs = $this->config['contenttypes'];

    }

    public function slugTreeRecord($slug) {
        // Add snippets, since this is a Frontend route.
        $this->app['htmlsnippets'] = true;

        $parents= self::getTreeParents();
        $slug = \Bolt\Helpers\String::slug($slug, -1);

        // slug is strucutre
        if ( isset($parents[$slug]) ) {
            $contenttype = 'Structures';
            return Bolt\Controllers\Frontend::record($this->app , $contenttype, $slug);
        }
        else {
            $contenttype = self::getContenttypeBySlug($slug);
            return Bolt\Controllers\Frontend::record($this->app , $contenttype, $slug);
        }
    }

    /**
     * handles structure request
     *
     * @param (string) structure slug
     * @param (string) slug
     */
    public function structureTreeRecord($structureSlugs, $slug) {
        // Add snippets, since this is a Frontend route.
        $this->app['htmlsnippets'] = true;

        $parents = self::getTreeParents();

        // slug is strucutre
        if ( isset($parents[$slug]) ) {
            $parent = $parents[$parents[$slug]['parentslug']];

            // validate url
            if ( !isset($parent) || $parent['testpath'] != $structureSlugs)
                self::abort($slug);

            $contenttype = 'structures';
            return Bolt\Controllers\Frontend::record($this->app , $contenttype, $slug);
        }
        else {
            $contenttype = self::getContenttypeBySlug($slug);
            return Bolt\Controllers\Frontend::record($this->app , $contenttype, $slug);
        }
    }

    public function getContenttypeBySlug($slug) {
        foreach ($this->contenttypeslugs as $contenttypeslug ) {
            $content = $this->app['storage']->getContent($contenttypeslug, array('slug' => $slug, returnsingle => true));
            if ($content)
                return $contenttypeslug;
        }
        return 0;
    }

    public function getContenttype($slug) {
        return $this->app['storage']->getContentType($slug);
    }

    /**
     * Search contenttype by structure slug
     *
     * @param string
     * @return void
     */
    public function getContenttypeByStructure($structureSlug) {
        foreach ($this->contenttypeslugs as $contenttypeslug ) {
            $content = $this->app['storage']->getContent($contenttypeslug, array(tree => $structureSlug, order => 'datepublish', returnsingle => true));

            if ($content->values) {
                return $this->app['storage']->getContentType($contenttypeslug);
            }
        }
        return 0;
    }



    /**
     * update strucuture taxnomoy options
     * todo: add structuretree exeption
     *
     * @param Bolt\StorageEvent
     * @return void
     */
    public function updateStructureTaxonomy(\Bolt\StorageEvent $event) {
        if ($event->getContentType() == 'structures') {
            $yaml = new Yaml();
            $file = BOLT_CONFIG_DIR . '/taxonomy.yml';
            $tax = $yaml->parse($file);
            $options = array();

            $structures = $this->app['storage']->getContent('structures', array( order => 'title') );
            foreach($structures as $structure) {
                $options[$structure->values['slug']] = $structure->values['title'];
            }

            $tax['tree']['options'] = $options;

            file_put_contents($file, $yaml->dump($tax));

            // delete session parents structures
            $this->app['session']->set('parents', null);

            return 0;
        }
    }

    public function getBaseStructure($record, $parents)
    {
        $contenttype = $record->contenttype['name'];

        // search structure with assigned contenttype
        foreach ($parents as $parent) {
            if ($parent['content'] == $contenttype) {
                $parentContent = $parent;
                break;
            }
        }

        return $parentContent;
    }


    /**
     * Generate contentlink for structure binded records
     * contenttypes can can belong to more than one stucture.
     * If no parent is set return default link.
     */
    public function getStructureLink($record)
    {
        $parents = self::getTreeParents();
        $parentSlug = $record->group['slug'];
        $parentContent = self::getBaseStructure($record, $parents);

        // parent link
        if ($parentSlug) {
            $link = $parents[$parentSlug]['path'] . $record['slug'];
        }
        // link of default structure for contenttype
        else if ($parentContent) {
            $link = $parentContent['path'] . $record['slug'];
        }
        // default link
        else $link = $record->link();

        return $link;
    }

    private function abort($slug)
    {
        // There's one special edge-case we check for: if the request is for the backend, without trailing
        // slash and it is intercepted by custom routing, we forward the client to that location.
        if ($slug == trim($this->app['config']->get('general/branding/path'), "/")) {
            simpleredirect($this->app['config']->get('general/branding/path') . "/");
        }

        // try to get related
        // $pageContent = $this->app['storage']->getContent('pages', array('slug' => $slug,'status'=> '!published', 'returnsingle' => true));
        // var_dump( $pageContent->taxonomy );

        $this->app->abort(404, "Page $contenttypeslug/$slug not found.");
    }

    public function getMixedContent($contenttype, $treeSlug) {
        $content = $this->app['storage']->getContent($contenttype, array(tree => $treeSlug) );

        echo sizeof($content);
    }

    /**
     * Search subsite in parents by parentid
     * @param $record (object)
     * @return $parent (object)
     */
    public function subSite($record)
    {
        $parents = self::getTreeParents();
        $parentSlug = $record->group['slug'];
        $contenttype = $record->contenttype['name'];

        if ($contenttype == 'Structures')
            $parentSlug = $record['slug'];

        // find structure with content by contenttype
        if (!$parentSlug) {
            foreach ($parents as $parent) {
                if ( !$parentSlug && $parent['content'] == $contenttype ) {
                    $parentSlug = $parent['slug'];
                }
            }
        }

        while ($parentSlug) {
            $parent = $parents[$parentSlug];

            if ( $parent['subsite'] && $parent['subsite']['subclass'] != 'none') {
                return $parent['subsite'];
            }

            $parentSlug = $parent['parentslug'];
        }
        return 0;
    }

    /**
     * build breadcrumb
     * @return (array) breadcumb
     */
    public function breadCrumb($record) {

        $parents = self::getTreeParents();
        $parentSlug = $record->group['slug'];
        $items = array();

        $baseParent = self::getBaseStructure($record, $parents);
        if ($baseParent && !$parentSlug)
            $parentSlug = $baseParent['slug'];

        while ($parentSlug) {
            $parent = $parents[$parentSlug];
            $items[] = $parent;
            $parentSlug = $parents[$parentSlug]['parentslug'];
        }
        return array_reverse($items);
    }

    public function sortObject($records, $sortby)
    {
        $callbackArraySort = function($a, $b) use ($sortby) {
            return strcmp($a->{($sortby)}, $b->{($sortby)});
        };

        usort($records, $callbackArraySort);

        return $records;
    }

    private function getTreeParents()
    {
        // check for parents in session
        if (is_array( $this->app['session']->get('parents') )) {
            return $this->app['session']->get('parents');
        }

        $structures = $this->app['storage']->getContent('structures');
        $parents = array();
        $structuresSlugs = array();

        // build structure parent object
        foreach ( $structures as $structure ) {
            $id = $structure->values['id'];
            $slug = $structure->values['slug'];
            $p = array(
                    'path' => array(),
                    'structure' => array()
                    );

            array_push($structuresSlugs, $structure->values['slug'] );
            $tempParents = self::getParents($structure, $structures, $p);
            $parents[$slug] = array(
                    'id' => $id,
                    'title' => $structure->values['title'],
                    'slug' => $structure->values['slug'],
                    'content' => $structure->values['content'],
                    'subsite' => ($structure->values['subclass']) ? array(
                        'subclass' => $structure->values['subclass'],
                        'footer' => $structure->values['footer'],
                        'path' => $parents[$id]['path']
                        ) : null,
                    'parentslug' => $structure->group['slug'],
                    'parents' => array_reverse($tempParents['structure'], true),
                    'path' => $this->app['paths']['root'] . join('/',array_reverse($tempParents['path'], true)) . '/',
                    'testpath' => join('/', array_reverse($tempParents['path'], true) )
                    );

            // set subsite options
            if (strpos($structure->values['template'],'subsite') !== false)
                $parents[$id]['subsite'] = array(
                        'subclass' => $structure->values['subclass'],
                        'footer' => $structure->relation['footer'][0],
                        'path' => $parents[$id]['path']
                        );
        }

        $this->app['session']->set('parents', $parents);
        $this->app['session']->set('structuresslugs', $structuresSlugs);

        return $parents;
    }

    private function getParents($structure, $structures, $p )
    {
        array_push($p['structure'], $structure->values );
        array_push($p['path'], $structure->values['slug'] );

        $parentId = $structure->values['parent'];
        $parentSlug = $structure->group['slug'];

        if ( $structure->group['slug'] != "" ) {
            return self::getParents( self::getStructureBySlug($parentSlug, $structures), $structures, $p);
        }
        else {
            return $p;
        }
    }

    private function getStructureBySlug($slug, $structures)
    {
        foreach ($structures as $structure) {
            if ($structure->values['slug'] == $slug)
                return $structure;
        }

        return 0;
    }


    /**
     * xml sitemap listing
     */
    public function xmlSitemap()
    {
        return self::sitemap(true);
    }

    /**
     * sitemap
     */
    public function sitemap($xml = false)
    {
        if($xml){
            $this->app['extensions']->clearSnippetQueue();
            $this->app['extensions']->disableJquery();
            $this->app['debugbar'] = false;
        }

        $sitemap = [];
        $pages = [];
        $template = ($xml) ? $this->config['sitemap']['xml_template'] : $this->config['sitemap']['template'];
        $sources = $this->config['sitemap']['sources'];
        $contenttypes = $this->config['sitemap']['contenttypes'];

        // get content
        foreach ($contenttypes as $contenttype ) {
            $pages = array_merge($pages, $this->app['storage']->getContent($contenttype));
        }

        // get sitemap sources
        foreach ($sources as $source) {
            $item = [];

            // entry type 'menu'
            if ($source['type'] == 'menu') {
                $menu = $this->app['config']->get('menu/'.$source['data']['menu']);
                foreach ($menu as $entry) {
                    $slug = makeSlug($entry['path'], -1);
                    $itemRaw = $this->app['storage']->getContent('structures', array('slug' => $slug, 'returnsingle' => true));
                    $item = $itemRaw->values;
                    $childsUnsorted = self::getChilds($pages, $itemRaw['slug']);
                    $item['childs'] = self::sortObject($childsUnsorted, 'sortorder');
                    $item['link'] = $this->app['paths']['root'] . $item['slug'];
                    array_push($sitemap, $item);
                }
            }
            // entrie type 'list'
            elseif ($source['type'] == 'list') {
                $itemRoot = [];
                $itemRoot['title'] = $source['label'];
                $itemRoot['childs'] = [];

                foreach ($source['data'] as $entry) {
                    // get link entries
                    if ($entry['link']) {
                        $item = array('title'=> $entry['label'], 'link' => $entry['link']);
                        array_push($itemRoot['childs'], $item);
                    }
                    // get slug entries
                    elseif ($entry['slug']) {
                        $slug = makeSlug($entry['slug'], -1);
                        $itemRaw = [];
                        foreach ($contenttypes as $contenttype ) {
                            $itemRaw = $this->app['storage']->getContent($contenttype, array('slug' => $slug, 'returnsingle' => true));
                            if ($itemRaw) break;
                        }
                        $item = $itemRaw->values;
                        $item['link'] = $this->app['paths']['root'] . $item['slug'];
                        $item['childs'] = self::getChilds($pages, $itemRaw['slug']);
                        array_push($itemRoot['childs'], $item);
                    }
                }
                array_push($sitemap, $itemRoot);
            }
        }

        $this->app['twig.loader.filesystem']->addPath(__DIR__);

        $headers = array();
        if ($xml) {
            $headers['Content-Type'] = 'application/xml; charset=utf-8';
            $temp = [];
            self::flat($sitemap, $temp);
            $sitemap = $temp;
        }

        $body = $this->app['render']->render($template, array(
                    'entries' => $sitemap,
                    'title' => 'Sitemap'
                    ));

        return new Response($body, 200, $headers);
    }

    private function flat($sitemapItem, &$f) {
        foreach ($sitemapItem as $item) {
            if (isset($item['childs']) ){
                $f[] = $item;
                self::flat($item['childs'], $f);
            }
            else {
                $f[] = $item;
            }
        }
    }

    private function getChilds(&$pages, $parentSlug, $p = [])
    {
        foreach ($pages as $page ) {
            if ( isset($page->group['slug']) && $page->group['slug'] == $parentSlug ) {
                $temp = $page->values;
                $temp['link'] = self::getStructureLink($page);
                $temp['childs'] = self::getChilds($pages, $page['slug'] );
                $p[] = $temp;
            }
        }

        return $p;
    }
}