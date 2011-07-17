# Copyright 2007 Rene Rivera
# Copyright 2011 Daniel James
# Distributed under the Boost Software License, Version 1.0.
# (See accompanying file LICENSE_1_0.txt or http://www.boost.org/LICENSE_1_0.txt)

import os, sys, subprocess, glob, re, time, xml.dom.minidom, codecs, urlparse
import boost_site.templite, boost_site.pages, boost_site.boostbook_parser, boost_site.util
from boost_site.settings import settings

################################################################################

def init():
    os.chdir(os.path.join(os.path.dirname(sys.argv[0]), "../"))

    import boost_site.upgrade
    boost_site.upgrade.upgrade()

def load_pages():
    return boost_site.pages.Pages('site-tools/state/feed-pages.txt')

def refresh_quickbook():
    update_quickbook(True)

def update_quickbook(refresh = False):
    # Now check quickbook files.
    
    pages = load_pages()

    if not refresh:
        scan_for_new_quickbook_pages(pages)
    
    # Translate new and changed pages

    pages.convert_quickbook_pages(refresh)

    # Generate 'Index' pages

    index_page_variables = {
        'pages' : pages,
        'downloads' : pages.match_pages(settings['downloads'], sort = False)
    }

    for index_page in settings['index-pages']:
        boost_site.templite.write_template(
            index_page,
            settings['index-pages'][index_page],
            index_page_variables)

    # Generate RSS feeds

    if not refresh:
        old_rss_items_doc = xml.dom.minidom.parseString('''<items></items>''')
        old_rss_items = {}
        for feed_file in settings['feeds']:
            old_rss_items.update(pages.load_rss(feed_file, old_rss_items_doc))
    
        for feed_file in settings['feeds']:
            feed_data = settings['feeds'][feed_file]
            rss_feed = generate_rss_feed(feed_file, feed_data)
            rss_channel = rss_feed.getElementsByTagName('channel')[0]
            
            feed_pages = pages.match_pages(feed_data['matches'])
            if 'count' in feed_data:
                feed_pages = feed_pages[:feed_data['count']]
            
            for qbk_page in feed_pages:
                if qbk_page.loaded:
                    item = generate_rss_item(rss_feed, qbk_page.qbk_file, qbk_page)
                    pages.add_rss_item(item)
                    rss_channel.appendChild(item['item'])
                elif qbk_page.qbk_file in old_rss_items:
                    rss_channel.appendChild(
                        rss_feed.importNode(
                            old_rss_items[qbk_page.qbk_file]['item'], True))
                else:
                    print "Missing entry for %s" % qbk_page.qbk_file
                    
            output_file = open(feed_file, 'w')
            try:
                output_file.write(rss_feed.toxml('utf-8'))
            finally:
                output_file.close()

    pages.save()

def scan_for_new_quickbook_pages(pages):
    for location in settings['pages']:
        pages_data = settings['pages'][location]
        for src_file_pattern in pages_data['src_files']:
            for qbk_file in glob.glob(src_file_pattern):
                pages.add_qbk_file(qbk_file, location)

    pages.save()


################################################################################

def generate_rss_feed(feed_file, details):
    rss = xml.dom.minidom.parseString('''<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:boostbook="urn:boost.org:boostbook">
  <channel>
    <generator>BoostBook2RSS</generator>
    <title>%(title)s</title>
    <link>%(link)s</link>
    <description>%(description)s</description>
    <language>%(language)s</language>
    <copyright>%(copyright)s</copyright>
  </channel>
</rss>
''' % {
    'title' : details['title'].encode('utf-8'),
    'link' : "http://www.boost.org/" + feed_file,
    'description' : '',
    'language' : 'en-us',
    'copyright' : 'Distributed under the Boost Software License, Version 1.0. (See accompanying file LICENSE_1_0.txt or http://www.boost.org/LICENSE_1_0.txt)'
    } )

    return rss

def generate_rss_item(rss_feed, qbk_file, page):
    assert page.loaded

    page_link = 'http://www.boost.org/%s' % page.location

    item = rss_feed.createElement('item')

    node = xml.dom.minidom.parseString('<title>%s</title>'
        % page.title_xml.encode('utf-8'))
    item.appendChild(rss_feed.importNode(node.documentElement, True))

    node = xml.dom.minidom.parseString('<link>%s</link>'
        % page_link.encode('utf-8'))
    item.appendChild(rss_feed.importNode(node.documentElement, True))

    node = xml.dom.minidom.parseString('<guid>%s</guid>'
        % page_link.encode('utf-8'))
    item.appendChild(rss_feed.importNode(node.documentElement, True))

    # TODO: Convert date format?
    node = rss_feed.createElement('pubDate')
    node.appendChild(rss_feed.createTextNode(page.pub_date))
    item.appendChild(node)

    node = rss_feed.createElement('description')
    # Placing the description in a root element to make it well formed xml.
    description = xml.dom.minidom.parseString(
        '<x>%s</x>' % page.description_xml.encode('utf-8'))
    base_links(description, page_link)
    node.appendChild(rss_feed.createTextNode(
        boost_site.util.fragment_to_string(description)))
    item.appendChild(node)

    return({
        'item': item,
        'quickbook': qbk_file,
        'last_modified': page.last_modified
    })

def base_links(node, base_link):
    base_element_links(node, base_link, 'a', 'href')
    base_element_links(node, base_link, 'img', 'src')

def base_element_links(node, base_link, tag_name, attribute):
    if node.nodeType == node.ELEMENT_NODE or \
            node.nodeType == node.DOCUMENT_NODE:
        for x in node.getElementsByTagName(tag_name):
            x.setAttribute(attribute,
                    urlparse.urljoin(base_link, x.getAttribute(attribute)))
    elif node.nodeType == node.DOCUMENT_FRAGMENT_NODE:
        for x in node.childNodes:
            base_element_links(x, base_link, tag_name, attribute)
 
################################################################################
