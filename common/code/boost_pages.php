<?php
# Copyright 2011, 2015 Daniel James
# Distributed under the Boost Software License, Version 1.0.
# (See accompanying file LICENSE_1_0.txt or http://www.boost.org/LICENSE_1_0.txt)

class BoostPages {
    // If you change these values, they will only apply to new pages.
    var $page_locations = array(
        array(
            'source' => 'feed/history/*.qbk',
            'destination' => 'users/history',
            'section' => 'history'
        ),
        array(
            'source' => 'feed/news/*.qbk',
            'destination' => 'users/news',
            'section' => 'news',
        ),
        array(
            'source' => 'feed/downloads/*.qbk',
            'destination' => 'users/download',
            'section' => 'downloads'
        ),
    );

    var $root;
    var $hash_file;
    var $page_cache_file;
    var $pages = Array();
    var $page_cache = Array();
    var $releases = null;

    function __construct($root, $hash_file, $page_cache, $release_file) {
        $this->root = $root;
        $this->hash_file = "{$root}/{$hash_file}";
        $this->page_cache_file = "{$root}/{$page_cache}";;
        $this->releases = new BoostReleases("{$root}/{$release_file}");

        if (is_file($this->hash_file)) {
            foreach(BoostState::load($this->hash_file) as $qbk_file => $record) {
                if (!isset($record['section'])) {
                    $location_data = $this->get_page_location_data($qbk_file);
                    $record['section'] = $location_data['section'];
                }
                $this->pages[$qbk_file]
                    = new BoostPages_Page($qbk_file,
                        $this->get_release_data($qbk_file, $record['section']),
                        $record);
            }
        }

        if (is_file($this->page_cache_file)) {
            $this->page_cache = BoostState::load($this->page_cache_file);
        }
    }

    function save() {
        BoostState::save(
            array_map(function($page) { return $page->state(); }, $this->pages),
            $this->hash_file);
        BoostState::save($this->page_cache,  $this->page_cache_file);
    }

    function reverse_chronological_pages() {
        $pages = $this->pages;

        $pub_date_order = array();
        $last_published_order = array();
        $unpublished_date = new DateTime("+10 years");
        foreach($pages as $index => $page) {
            $pub_date_order[$index] =
                ($page->release_data ?
                    BoostWebsite::array_get($page->release_data, 'release_date') :
                    null) ?:
                $page->pub_date ?: $unpublished_date;
            $last_published_order[$index] = $page->last_modified;
        }
        array_multisort(
            $pub_date_order, SORT_DESC,
            $last_published_order, SORT_DESC,
            $pages);

        return $pages;
    }

    function scan_for_new_quickbook_pages() {
        foreach ($this->page_locations as $details) {
            foreach (glob("{$this->root}/{$details['source']}") as $qbk_file) {
                assert(strpos($qbk_file, $this->root) === 0);
                $qbk_file = substr($qbk_file, strlen($this->root) + 1);
                $this->add_qbk_file($qbk_file, $details['section']);
            }
        }
    }

    function get_page_location_data($qbk_path) {
        foreach ($this->page_locations as $details) {
            if (fnmatch($details['source'], $qbk_path)) {
                return $details;
            }
        }
        throw new BoostException("Unexpected quickbook file path: {$qbk_path}");
    }

    function get_release_data($qbk_file, $section) {
        if ($section != 'history' && $section != 'downloads') {
            return null;
        }

        $basename = pathinfo($qbk_file, PATHINFO_FILENAME);

        if (preg_match('@^([a-z](?:_[a-z]|[a-z0-9])*)_([0-9][0-9_]*)$@i', $basename, $match)) {
            return $this->releases->get_latest_release_data($match[1], $match[2]);
        }
        else {
            return null;
        }

    }

    function add_qbk_file($qbk_file, $section) {
        $record = null;

        if (!isset($this->pages[$qbk_file])) {
            $release_data = $this->get_release_data($qbk_file, $section);
            $record = new BoostPages_Page($qbk_file, $release_data);
            $record->section = $section;
            $this->pages[$qbk_file] = $record;
        } else {
            $record = $this->pages[$qbk_file];
        }

        switch($record->get_release_status()) {
        case 'released':
        case null:
            $context = hash_init('sha256');
            hash_update($context, json_encode($this->normalize_release_data(
                $record->release_data, $qbk_file, $section)));
            hash_update($context, str_replace("\r\n", "\n",
                file_get_contents("{$this->root}/{$qbk_file}")));
            $qbk_hash = hash_final($context);

            if ($record->qbk_hash != $qbk_hash && $record->page_state != 'new') {
                $record->page_state = 'changed';
            }
            break;
        case 'beta':
            // For beta files, don't hash the page source as we don't want to
            // rebuild when it updates.
            $context = hash_init('sha256');
            hash_update($context, json_encode($this->normalize_release_data(
                $record->release_data, $qbk_file, $section)));
            $qbk_hash = hash_final($context);

            if ($record->qbk_hash != $qbk_hash && !$record->page_state) {
                $record->page_state = 'release-data-changed';
            }
            break;
        case 'dev':
            // Not building anything for dev entries (TODO: Maybe delete a page
            // if it exists??? Not sure how that would happen).
            break;
        default:
            // Unknown release status.
            assert(false);
        }

        if ($record->page_state) {
            $record->qbk_hash = $qbk_hash;
            $record->section = $section;
            $record->last_modified = new DateTime();
        }
    }

    // Make the release data look like it used to look in order to get a consistent
    // hash value. Pretty expensive, but saves constant messing around with hashes.
    private function normalize_release_data($release_data, $qbk_file, $section) {
        if (is_null($release_data)) { return null; }

        // Note that this can be determined from the quickbook file, so if
        // there's someway that it could change, then either qbk_hash or the
        // path would change anyway.
        unset($release_data['release_name']);

        // Fill in default values.
        $release_data += array(
                'release_notes' => $qbk_file, 'release_status' => 'released',
                'version' => '', 'documentation' => null, 'download_page' => null);

        // Release date wasn't originally included in data for old versions,
        // and shouldn't be changed, so easiest to ignore it.
        if (array_key_exists('release_date', $release_data) && (
                $section == 'downloads' ||
                !$release_data['version'] ||
                $release_data['version']->compare('1.62.0') <= 0
            )) {
            unset($release_data['release_date']);
        }

        // Arrange the keys in order.
        $release_data = $this->arrange_keys($release_data, array(
            'release_notes', 'release_status', 'version', 'documentation',
            'download_page', 'downloads', 'signature', 'third_party'));

        // Replace version object with version string.
        if (array_key_exists('version', $release_data)) {
            $release_data['version'] = (string) $release_data['version'];
        }

        // Turn the downloads and third party downloads into numeric arrays.
        if (array_key_exists('downloads', $release_data)) {
            $new_downloads = $this->arrange_keys($release_data['downloads'],
                array('bz2', 'gz', '7z', 'exe', 'zip'));
            foreach($new_downloads as &$record) { krsort($record); }
            unset($record);
            $release_data['downloads'] = array_values($new_downloads);
        }
        if ($release_data && array_key_exists('third_party', $release_data)) {
            $release_data['third_party'] = array_values($release_data['third_party']);
        }
        if ($release_data && array_key_exists('signature', $release_data)) {
            $release_data['signature'] = $this->arrange_keys($release_data['signature'], array(
                'location', 'name', 'key'));
        }


        return $release_data;
    }

    private function arrange_keys($array, $key_order) {
        $key_order = array_flip($key_order);
        $key_sort1 = array();
        $key_sort2 = array();
        foreach ($array as $key => $data) {
            $key_sort1[$key] = array_key_exists($key, $key_order) ? $key_order[$key] : 999;
            $key_sort2[$key] = $key;
        }
        array_multisort($key_sort1, SORT_ASC, $key_sort2, SORT_ASC, $array);
        return $array;
    }

    function convert_quickbook_pages($refresh = false) {
        try {
            BoostSuperProject::run_process('quickbook --version');
            $have_quickbook = true;
        }
        catch(ProcessError $e) {
            echo "Problem running quickbook, will not convert quickbook articles.\n";
            $have_quickbook = false;
        }

        $in_progress_release_notes = array();
        $in_progress_failed = false;
        foreach ($this->pages as $page => $page_data) {
            if ($page_data->dev_data) {
                $dev_page_data = clone($page_data);
                $dev_page_data->release_data = $dev_page_data->dev_data;
                $dev_page_data->page_state = 'changed';

                if (!$this->convert_quickbook_page($page, $dev_page_data, $have_quickbook)) {
                    echo "Unable to generate In Progress release notes\n";
                    $in_progress_failed = true;
                }
                else {
                    $in_progress_release_notes[] = array(
                        'full_title_xml' => $dev_page_data->title_xml,
                        'web_date' => 'In Progress',
                        'download_table' => $dev_page_data->download_table(),
                        'description_xml' => $dev_page_data->description_xml,
                    );
                }
            }

            // TODO: Refresh should ignore beta/dev releases?
            if ($page_data->page_state || $refresh) {
                if ($this->convert_quickbook_page($page, $page_data, $have_quickbook)) {
                    $this->generate_quickbook_page($page, $page_data);

                    if ($page_data->fresh_cache) {
                        $page_data->page_state = null;
                    }
                }
            }
        }

        if (!$in_progress_failed) {
            $template_vars = array(
                'releases' => $in_progress_release_notes,
            );
            self::write_template(
                "{$this->root}/users/history/in_progress.html",
                __DIR__."/templates/in_progress.php",
                $template_vars);
        }
    }

    function convert_quickbook_page($page, $page_data, $have_quickbook) {
        $bb_parser = new BoostBookParser();

        // Hash the quickbook source
        $hash = hash('sha256', str_replace("\r\n", "\n",
            file_get_contents("{$this->root}/{$page}")));

        // Get the page from quickbook/read from cache
        $fresh_cache = false;
        $boostbook_values = null;

        switch ($page_data->get_release_status()) {
        case 'beta':
            $page_cache_key = "{$page}:{$page_data->release_data['version']}";
            list($boostbook_values, $fresh_cache) = $this->load_from_cache($page_cache_key, $hash);
            // For page state 'release data changed' we want to use the cached page regardless,
            // so mark it as fresh.
            if ($boostbook_values && $page_data->page_state === 'release-data-changed') { $fresh_cache = true; }
            // If the cached beta notes aren't fresh, then use the dev notes which might be,
            // and should at least be more up to date.
            if (!$fresh_cache) {
                list($alt_boostbook_values, $alt_fresh_cache) = $this->load_from_cache($page, $hash);
                if ($alt_boostbook_values) {
                    $boostbook_values = $alt_boostbook_values;
                    $fresh_cache = $alt_fresh_cache;
                    $this->page_cache[$page_cache_key] = $alt_boostbook_values;
                }
            }
            break;
        default:
            $page_cache_key = $page;
            list($boostbook_values, $fresh_cache) = $this->load_from_cache($page_cache_key, $hash);
        }

        if ($have_quickbook && !$fresh_cache)
        {
            $xml_filename = tempnam(sys_get_temp_dir(), 'boost-qbk-');
            try {
                echo "Converting ", $page, ":\n";
                BoostSuperProject::run_process("quickbook --output-file {$xml_filename} -I {$this->root}/feed {$this->root}/{$page}");
                $values = $bb_parser->parse($xml_filename);
                $boostbook_values = array(
                    'hash' => $hash,
                    'title_xml' => BoostSiteTools::trim_lines($values['title_xhtml']),
                    'purpose_xml' => BoostSiteTools::trim_lines($values['purpose_xhtml']),
                    'notice_xml' => BoostSiteTools::trim_lines($values['notice_xhtml']),
                    'notice_url' => $values['notice_url'],
                    'pub_date' => $values['pub_date'],
                    'id' => $values['id'],
                    'description_xhtml' => BoostSiteTools::trim_lines($values['description_xhtml']),
                );
            } catch (Exception $e) {
                unlink($xml_filename);
                throw $e;
            }
            unlink($xml_filename);

            $this->page_cache[$page_cache_key] = $boostbook_values;
            $fresh_cache = true;
        }

        if ($boostbook_values) {
            $description_xhtml = $boostbook_values['description_xhtml'];
            if (array_key_exists('title_xml', $boostbook_values)) {
                $page_data->load_boostbook_data($boostbook_values);
            }
        }
        else {
            echo "Unable to generate page for {$page}.\n";
            return false;
        }

        if (!$fresh_cache) {
            // If we have a dated cache entry, and aren't able to
            // rebuild it, continue using the current entry, but
            // don't change the page state - it will try
            // again on the next run.
            echo "Using old cached entry for {$page}.\n";
        }

        // Set the path where the page should be built.
        // This can only be done after the quickbook file has been converted,
        // as the page id is based on the file contents.

        if (!$page_data->location) {
            $location_data = $this->get_page_location_data($page_data->qbk_file);
            $page_data->location = "{$location_data['destination']}/{$page_data->id}.html";
        }

        // Transform links in description

        if (($page_data->get_release_status() === 'dev' ||
            $page_data->get_release_status() === 'beta') &&
            $page_data->get_documentation()
        ) {
            $doc_prefix = rtrim($page_data->get_documentation(), '/');
            $description_xhtml = BoostSiteTools::transform_links_regex($description_xhtml,
                '@^(?=/libs/|/doc/html/)@', $doc_prefix);

            $version = BoostWebsite::array_get($page_data->release_data, 'version');
            if ($version && $version->is_numbered_release()) {
                $final_documentation = "/doc/libs/{$version->final_doc_dir()}";
                $description_xhtml = BoostSiteTools::transform_links_regex($description_xhtml,
                    '@^'.preg_quote($final_documentation, '@').'(?=/)@', $doc_prefix);
            }
        }

        $description_xhtml = BoostSiteTools::trim_lines($description_xhtml);
        $page_data->fresh_cache = $fresh_cache;
        $page_data->description_xml = $description_xhtml;
        return true;
    }

    function load_from_cache($page_cache_key, $hash) {
        if (array_key_exists($page_cache_key, $this->page_cache)) {
            return array(
                $this->page_cache[$page_cache_key],
                $this->page_cache[$page_cache_key]['hash'] === $hash);
        }
        else {
            return array(null, false);
        }
    }

    function generate_quickbook_page($page, $page_data) {
        $template_vars = array(
            'history_style' => '',
            'full_title_xml' => $page_data->full_title_xml(),
            'title_xml' => $page_data->title_xml,
            'note_xml' => '',
            'web_date' => $page_data->web_date(),
            'documentation_para' => '',
            'download_table' => $page_data->download_table(),
            'description_xml' => $page_data->description_xml,
        );

        if ($page_data->get_documentation()) {
            $template_vars['documentation_para'] = '              <p><a href="'.html_encode($page_data->get_documentation()).'">Documentation</a>';
        }

        if (strpos($page_data->location, 'users/history/') === 0) {
            $template_vars['history_style'] = <<<EOL

  <style type="text/css">
/*<![CDATA[*/
  #content .news-description ul {
    list-style: none;
  }
  #content .news-description ul ul {
    list-style: circle;
  }
  /*]]>*/
  </style>

EOL;
        }

        self::write_template(
            "{$this->root}/{$page_data->location}",
            __DIR__."/templates/entry.php",
            $template_vars);
    }

    static function write_template($_location, $_template, $_vars) {
        ob_start();
        extract($_vars);
        include($_template);
        $r = ob_get_contents();
        ob_end_clean();
        file_put_contents($_location, $r);
    }
}

class BoostPages_Page {
    var $qbk_file;

    var $section, $page_state, $location;
    var $id, $title_xml, $purpose_xml, $notice_xml, $notice_url;
    var $last_modified, $pub_date;
    var $qbk_hash;

    // Extra state data that isn't saved.
    var $fresh_cache = false; // Is the page markup in the cache up to date.
    var $description_xml = null; // Page markup, after transforming for current state.
    var $is_release = false;     // Is this a relase?
    var $release_data = null;    // Status of release where appropriate.
    var $dev_data = null; // Status of release in development.

    function __construct($qbk_file, $release_data = null, $attrs = array()) {
        $this->qbk_file = $qbk_file;
        if ($release_data) {
            $this->is_release = true;
            $this->release_data = BoostWebsite::array_get($release_data, 'release');
            $this->dev_data = BoostWebsite::array_get($release_data, 'dev');
        }

        $this->section = BoostWebsite::array_get($attrs, 'section');
        $this->page_state = BoostWebsite::array_get($attrs, 'page_state');
        $this->location = BoostWebsite::array_get($attrs, 'location');
        $this->id = BoostWebsite::array_get($attrs, 'id');
        $this->title_xml = BoostWebsite::array_get($attrs, 'title');
        $this->purpose_xml = BoostWebsite::array_get($attrs, 'purpose');
        $this->notice_xml = BoostWebsite::array_get($attrs, 'notice');
        $this->notice_url = BoostWebsite::array_get($attrs, 'notice_url');
        $this->last_modified = BoostWebsite::array_get($attrs, 'last_modified');
        $this->pub_date = BoostWebsite::array_get($attrs, 'pub_date');
        $this->qbk_hash = BoostWebsite::array_get($attrs, 'qbk_hash');

        // Ensure that pub_date as last_modified are DateTimes.
        // TODO: Probably not needed any more.
        if (is_string($this->pub_date)) {
            $this->pub_date = $this->pub_date == 'In Progress' ?
                null : new DateTime($this->pub_date);
        }
        else if (is_numeric($this->pub_date)) {
            $this->pub_date = new DateTime("@{$this->pub_date}");
        }

        if (is_string($this->last_modified)) {
            $this->last_modified = new DateTime($this->last_modified);
        }
        else if (is_numeric($this->last_modified)) {
            $this->last_modified = new DateTime("@{$this->last_modified}");
        }
    }

    function state() {
        return array(
            'section' => $this->section,
            'page_state' => $this->page_state,
            'location' => $this->location,
            'id'  => $this->id,
            'title' => $this->title_xml,
            'purpose' => $this->purpose_xml,
            'notice' => $this->notice_xml,
            'notice_url' => $this->notice_url,
            'last_modified' => $this->last_modified,
            'pub_date' => $this->pub_date,
            'qbk_hash' => $this->qbk_hash
        );
    }

    function load_boostbook_data($values) {
        $this->title_xml = $values['title_xml'];
        $this->purpose_xml = $values['purpose_xml'];
        $this->notice_xml = $values['notice_xml'];
        $this->notice_url = $values['notice_url'];
        $this->pub_date = $values['pub_date'];
        $this->id = $values['id'];
        if (!$this->id) {
            $this->id = strtolower(preg_replace('@[\W]@', '_', $this->title_xml));
        }
    }

    function full_title_xml() {
        switch($this->get_release_status()) {
        case 'beta':
            return trim("{$this->title_xml} beta {$this->release_data['version']->beta_number()}");
        case 'dev':
            return "{$this->title_xml} - work in progress";
        case 'released':
        case null:
            return $this->title_xml;
        default:
            assert(false);
        }
    }

    function web_date() {
        $date = null;

        if (!is_null($this->release_data)) {
            // For releases, use the release date, not the pub date
            if (array_key_exists('release_date', $this->release_data)) {
                $date = $this->release_data['release_date'];
            }
        }
        else {
            $date = $this->pub_date;
        }

        return $date ? gmdate('F jS, Y H:i', $date->getTimestamp()).' GMT' :
            'In Progress';
    }

    function download_table_data() {
        if (is_null($this->release_data)) { return null; }
        $downloads = BoostWebsite::array_get($this->release_data, 'downloads');
        $signature = BoostWebsite::array_get($this->release_data, 'signature');
        $third_party = BoostWebsite::array_get($this->release_data, 'third_party');
        if (!$downloads && !$third_party) { return $this->get_download_page(); }

        $tabled_downloads = array();
        foreach ($downloads as $download) {
            // Q: Good default here?
            $line_endings = BoostWebsite::array_get($download, 'line_endings', 'unix');
            unset($download['line_endings']);
            $tabled_downloads[$line_endings][] = $download;
        }

        $result = array(
            'downloads' => $tabled_downloads
        );
        if ($signature) { $result['signature'] = $signature; }
        if ($third_party) { $result['third_party'] = $third_party; }

        return $result;
    }

    function download_table() {
        $downloads = $this->download_table_data();

        if (is_array($downloads)) {
            # Print the download table.

            $hash_column = false;
            foreach($downloads['downloads'] as $x) {
                foreach($x as $y) {
                    if (array_key_exists('sha256', $y)) {
                        $hash_column = true;
                    }
                }
            }

            $output = '';
            $output .= '              <table class="download-table">';
            switch($this->get_release_status()) {
            case 'released':
                $output .= '<caption>Downloads</caption>';
                break;
            case 'beta':
                $output .= '<caption>Beta Downloads</caption>';
                break;
            case 'dev':
                $output .= '<caption>Development Downloads</caption>';
                break;
            default:
                assert(false);
            }
            $output .= '<tr><th scope="col">Platform</th><th scope="col">File</th>';
            if ($hash_column) {
                $output .= '<th scope="col">SHA256 Hash</th>';
            }
            $output .= '</tr>';

            foreach (array('unix', 'windows') as $platform) {
                $platform_downloads = $downloads['downloads'][$platform];
                $output .= "\n";
                $output .= '<tr><th scope="row"';
                if (count($platform_downloads) > 1) {
                    $output .= ' rowspan="'.count($platform_downloads).'"';
                }
                $output .= '>'.html_encode($platform).'</th>';
                $first = true;
                foreach ($platform_downloads as $download) {
                    if (!$first) { $output .= '<tr>'; }
                    $first = false;

                    $file_name = basename(parse_url($download['url'], PHP_URL_PATH));

                    $output .= '<td><a href="';
                    if (strpos($download['url'], 'sourceforge') !== false) {
                        $output .= html_encode($download['url']);
                    }
                    else {
                        $output .= html_encode($download['url']);
                    }
                    $output .= '">';
                    $output .= html_encode($file_name);
                    $output .= '</a></td>';
                    if ($hash_column) {
                        $output .= '<td>';
                        $output .= html_encode(BoostWebsite::array_get($download, 'sha256'));
                        $output .= '</td>';
                    }
                    $output .= '</tr>';
                }
            }

            $output .= '</table>';

            if (array_key_exists('signature', $downloads)) {
                $output .= "<p><a href='/".html_encode($downloads['signature']['location']).
                    "'>List of checksums</a> signed by ".
                    "<a href='".html_encode($downloads['signature']['key'])."'>".
                    html_encode($downloads['signature']['name'])."</a></p>\n";
            }

            if (array_key_exists('third_party', $downloads)) {
                $output .= "\n";
                $output .= "<h3>Third Party Downloads</h3>\n";
                $output .= "<ul>\n";
                foreach($downloads['third_party'] as $download) {
                    $output .= '<li>';
                    $output .= '<a href="'.html_encode($download['url']).'">';
                    $output .= html_encode($download['title']);
                    $output .= '</a>';
                    $output .= "</li>\n";
                }
                $output .= "</ul>\n";
            }

            return $output;
        } else if (is_string($downloads)) {
            # If the link didn't match the normal version number pattern
            # then just use the old fashioned link to sourceforge. */

            $output = '              <p><span class="news-download"><a href="'.
                html_encode($downloads).'">';

            switch($this->get_release_status()) {
            case 'released':
                $output .= 'Download this release.';
                break;
            case 'beta':
                $output .= 'Download this beta release.';
                break;
            case 'dev':
                $output .= 'Download snapshot.';
                break;
            default:
                assert(false);
            }

            $output .= '</a></span></p>';

            return $output;
        }
        else {
            return '';
        }
    }

    function is_published($state = null) {
        if ($this->page_state == 'new') {
            return false;
        }
        if ($this->is_release && !$this->release_data) {
            return false;
        }
        if (!is_null($state) && $this->get_release_status() !== $state) {
            return false;
        }
        return true;
    }

    function get_release_status() {
        switch ($this->section) {
        case 'history':
            if (!$this->is_release) {
                return null;
            }

            if (!$this->release_data) {
                return 'dev';
            }

            if (array_key_exists('release_status', $this->release_data)) {
                return $this->release_data['release_status'];
            }

            if ($this->release_data['version']->is_numbered_release()) {
                return $this->release_data['version']->is_beta() ? 'beta' : 'released';
            }
            else {
                return 'dev';
            }
        case 'downloads':
            return 'released';
        default:
            return null;
        }
    }

    function get_documentation() {
        return is_null($this->release_data) ? null : BoostWebsite::array_get($this->release_data, 'documentation');
    }

    function get_download_page() {
        return is_null($this->release_data) ? null : BoostWebsite::array_get($this->release_data, 'download_page');
    }
}
