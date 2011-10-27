# Copyright 2011 Daniel James
# Distributed under the Boost Software License, Version 1.0.
# (See accompanying file LICENSE_1_0.txt or http://www.boost.org/LICENSE_1_0.txt)

settings = {
    'downloads' : [
        {
            'anchor': 'live',
            'single': 'Current Release',
            'plural': 'Current Releases',
            'patterns': ['feed/history/boost_1_47_0.qbk']
        },
        {
            'anchor': 'beta',
            'single': 'Beta Release',
            'plural': 'Beta Releases',
            'patterns': ['feed/history/boost_1_48_0.qbk']
        }
    ],
    'pages': {
        'users/history/': {
            'src_files' : ['feed/history/*.qbk'],
            'template' : 'site-tools/templates/entry-template.html'
        },
        'users/news/': {
            'src_files' : ['feed/news/*.qbk'],
            'template' : 'site-tools/templates/entry-template.html'
        },
        'users/download/': {
            'src_files' : ['feed/downloads/*.qbk'],
            'template' : 'site-tools/templates/entry-template.html'
        }
    },
    'index-pages' : {
        'users/download/index.html' : 'site-tools/templates/download-template.html',
        'users/history/index.html' : 'site-tools/templates/history-template.html',
        'users/news/index.html' : 'site-tools/templates/news-template.html',
        'index.html' : 'site-tools/templates/index-src.html'
    },
    # See boost_site.pages for matches pattern syntax.
    #
    # glob [ '|' flag ]
    'feeds' : {
        'feed/downloads.rss' : {
            'title': 'Boost Downloads',
            'matches': ['feed/history/*.qbk|released', 'feed/downloads/*.qbk'],
            'count': 3
        },
        'feed/history.rss' : {
            'title': 'Boost History',
            'matches': ['feed/history/*.qbk|released']
        },
        'feed/news.rss' : {
            'title': 'Boost News',
            'matches': ['feed/news/*.qbk', 'feed/history/*.qbk|released'],
            'count': 5
        },
        'feed/dev.rss' : {
            'title': 'Release notes for work in progress boost',
            'matches': ['feed/history/*.qbk'],
            'count': 5
        }
    }
}
