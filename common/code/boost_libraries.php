<?php
/*
  Copyright 2006 Redshift Software, Inc.
  Distributed under the Boost Software License, Version 1.0.
  (See accompanying file LICENSE_1_0.txt or http://www.boost.org/LICENSE_1_0.txt)
*/

require_once(dirname(__FILE__) . '/boost_utility.php');
require_once(dirname(__FILE__) . '/boost_version.php');

class boost_libraries
{
    var $categories = array();
    private $db = array();
    
    function boost_libraries($xml_file)
    {
        $xml = implode("",file($xml_file));
        $parser = xml_parser_create();
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
        xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
        xml_parse_into_struct($parser, $xml, $values);
        xml_parser_free($parser);

        ##print '<!-- '; print_r($values); print ' -->';
        
        $lib = NULL;
        $category = NULL;
        foreach ( $values as $key => $val )
        {
            if ($val['tag'] == 'category' && $val['type'] == 'open' && !$lib && !$category)
            {
                $category = isset($val['attributes']) ? $val['attributes'] : array();
            }
            else if($val['tag'] == 'title' && $category)
            {
                $category['title'] = isset($val['value']) ? trim($val['value']) : '';
            }
            else if ($val['tag'] == 'category' && $val['type'] == 'close' && $category)
            {
                $category['libraries'] = array();
                $this->categories[$category['name']] = $category;
                $category = NULL;
            }
            else if ($val['tag'] == 'library' && $val['type'] == 'open')
            {
                $lib = array();
            }
            else if ($val['type'] == 'complete')
            {
                switch ($val['tag'])
                {
                    case 'key':
                    case 'name':
                    case 'authors':
                    case 'description':
                    case 'documentation':
                    {
                        if (isset($val['value'])) { $lib[$val['tag']] = trim($val['value']); }
                        else { $lib[$val['tag']] = ''; }
                    }
                    break;
                    case 'boost-version':
                    {
                        if (isset($val['value'])) { $lib[$val['tag']] = BoostVersion::from($val['value']); }
                        else { $lib[$val['tag']] = ''; }
                    }
                    break;
                    case 'std-proposal':
                    case 'std-tr1':
                    {
                        $value = isset($val['value']) ? trim($val['value']) : false;
                        if($value && $value != 'true' && $value != 'false') {
                            echo 'Invalid value for ',htmlentities($val['tag']),
                                ': ', $value, "\n";
                            exit(0);
                        }
                        $lib[$val['tag']] = ($value == 'true');
                    }
                    break;
                    case 'category':
                    {
                        if(isset($val['value'])) {
                            $name = trim($val['value']);
                            $lib['category'][] = $name;
                        }
                    }
                    break;
                    default:
                        echo 'Invalid tag: ', htmlentities($val['tag']), "\n";
                        exit(0);
                }
            }
            else if ($val['tag'] == 'library' && $val['type'] == 'close' && $lib)
            {
                $this->db[$lib['key']] = $lib;
                $lib = NULL;
            }
        }
    }
    
    function sort_by($field)
    {
        uasort($this->db, sort_by_field($field));
    }

    function get($sort = null, $filter = null) {
        $libs = $filter ? array_filter($this->db, $filter) : $this->db;
        if($sort) {
            uasort($libs, sort_by_field($sort));
        }
        return $libs;
    }

    function get_categorized($sort = null, $filter = null) {
        $libs = $this->get($sort, $filter);
        $categories = $this->categories;

        foreach($libs as $key => &$library) {
            foreach($library['category'] as $category) {
                if(!isset($this->categories[$category])) {
                    echo 'Unknown category: ', htmlentities($category), "\n";
                    exit(0);
                }
                $categories[$category]['libraries'][] = &$library;
            }
            unset($library);
        }

        return $categories;
    }
}
?>
