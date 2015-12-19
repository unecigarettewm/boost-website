<?php

# Copyright 2011, 2015 Daniel James
# Distributed under the Boost Software License, Version 1.0.
# (See accompanying file LICENSE_1_0.txt or http://www.boost.org/LICENSE_1_0.txt)

class BoostPageSettings
{
    static $downloads = array(
        array(
            'anchor' => 'live',
            'single' => 'Current Release',
            'plural' => 'Current Releases',
            'matches' => array('feed/history/*.qbk|released'),
            'count' => 1
        ),
        array(
            'anchor' => 'beta',
            'single' => 'Beta Release',
            'plural' => 'Beta Releases',
            'matches' => array('feed/history/*.qbk|beta')
        )
    );

    static $pages = array(
        'users/history/' => array(
            'src_files' => array('feed/history/*.qbk'),
            'type' => 'release'
        ),
        'users/news/' => array(
            'src_files' => array('feed/news/*.qbk'),
        ),
        'users/download/' => array(
            'src_files' => array('feed/downloads/*.qbk'),
            'type' => 'release'
        )
    );

    static $index_pages = array(
        'generated/download-items.html' => 'templates/download.php',
        'generated/history-items.html' => 'templates/history.php',
        'generated/news-items.html' => 'templates/news.php',
        'generated/home-items.html' => 'templates/index.php'
    );

    # See boost_site.pages for matches pattern syntax.
    #
    # glob array( '|' flag )
    static $feeds = array(
        'generated/downloads.rss' => array(
            'link' => 'users/download/',
            'title' => 'Boost Downloads',
            'matches' => array('feed/history/*.qbk|released', 'feed/downloads/*.qbk'),
            'count' => 3
        ),
        'generated/history.rss' => array(
            'link' => 'users/history/',
            'title' => 'Boost History',
            'matches' => array('feed/history/*.qbk|released')
        ),
        'generated/news.rss' => array(
            'link' => 'users/news/',
            'title' => 'Boost News',
            'matches' => array('feed/news/*.qbk', 'feed/history/*.qbk|released'),
            'count' => 5
        ),
        'generated/dev.rss' => array(
            'link' => '',
            'title' => 'Release notes for work in progress boost',
            'matches' => array('feed/history/*.qbk'),
            'count' => 5
        )
    );
}
