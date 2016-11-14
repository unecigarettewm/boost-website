<?php

// Call using something like:
//
//     curl http://www.boost.org/doc/generate.php?page=libs/libraries.htm&version=1.60.0

require_once(__DIR__.'/../common/code/bootstrap.php');

function main($args) {
    if (!array_key_exists('page', $args)) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 400 Missing page argument', true, 400);
        echo "Missing page argument.\n";
        exit(1);
    }

    switch(strtolower(trim($args['page']))) {
    case 'libs/libraries.htm':
    case 'index.html':
        $page = new LibrariesHtm($args);
        $page->display();
        break;
    default:
        header($_SERVER['SERVER_PROTOCOL'] . ' 404 Page not found', true, 404);
        echo "Unknown page: ", htmlentities($args['page']), "\n";
        exit(1);
    }
}

class LibrariesHtm {
    var $args;

    function __construct($args) {
        $this->args = $args;
    }

    function display() {
        // TODO: Specifying the version only works *after* a release, as
        //       new libraries will still have develop/master as their
        //       version. This works for now as version is always
        //       master/develop, but might change in the future.
        //
        //       Now that BoostVersion has 'prerelease' versions, could
        //       possibly use that for new libraries to handle this better.
        $version = BoostVersion::from(
            array_key_exists('version', $this->args) ? $this->args['version'] : 'master'
        );
        $page = $this->args['page'];
        $libs = BoostLibraries::load();

        $categorized = $libs->get_categorized_for_version($version, 'name',
            'BoostLibraries::filter_visible');
        // TODO: Shouldn't really have to sort this here.
        uasort($categorized, function($a, $b) {
            $a = $a['title'];
            $b = $b['title'];
            if ($a === 'Miscellaneous') { $a = 'ZZZZZZZZ'; }
            if ($b === 'Miscellaneous') { $b = 'ZZZZZZZZ'; }
            return ($a > $b) ?: ($a < $b ? -1 : 0);
        });

        $alphabetic = $libs->get_for_version($version, 'name',
            'BoostLibraries::filter_visible');

        $params = array(
            'categorized' => array(),
            'alphabetic' => array(),
            'unreleased_libs' => array(),
            'unreleased_lib_count' => 0,
        );

        foreach($categorized as $category) {
            $template_value = $category;
            $template_value['libraries'] = array();
            foreach($category['libraries'] as $index => $library) {
                $template_value['libraries'][] = $this->rewrite_library($library, $index);
            }
            $params['categorized'][] = $template_value;
        }

        foreach($alphabetic as $index => $library) {
            $params['alphabetic'][] = $this->rewrite_library($library, $index);
        }

        if (!$version->is_numbered_release()) {
            $index = 0;
            foreach ($alphabetic as $library) {
                if (!$library['boost-version']->is_final_release() &&
                    !$library['boost-version']->is_hidden())
                {
                    $params['unreleased_libs'][] = $this->rewrite_library($library, $index++);
                }
            }
        } else {
            $index = 0;
            foreach ($alphabetic as $library) {
                // Q: Also match point version?
                if ($library['boost-version']->base_version() == $version->base_version())
                {
                    $params['unreleased_libs'][] = $this->rewrite_library($library, $index++);
                }
            }
        }
        $params['unreleased_lib_count'] = count($params['unreleased_libs']);

        // Better support for other branches?
        $template_dir = BOOST_REPOS_DIR.'/boost-'.
            ((string) $version == 'develop' ? 'develop' : 'master').'/'.$page;
        echo BoostSimpleTemplate::render(file_get_contents($template_dir),
            $params);
    }

    function rewrite_library($lib, $index) {
        $lib['index'] = $index;
        $lib['link'] = $this->rewrite_link($lib['documentation']);
        $lib['description'] = rtrim(trim($lib['description']), '.');
        return $lib;
    }

    function rewrite_link($link) {
        $page_parts = explode('/', $this->args['page']);
        array_pop($page_parts);
        $link = trim(preg_replace('@/$@', '/index.html', $link), '/');
        $link_parts = explode('/', $link);
        while ($page_parts && $page_parts[0] == $link_parts[0]) {
            array_shift($page_parts);
            array_shift($link_parts);
        }
        return str_repeat('../', count($page_parts)).implode('/', $link_parts);
    }
}

main($_GET);
