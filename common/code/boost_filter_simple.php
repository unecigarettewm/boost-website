<?php
/*
  Copyright 2005-2008 Redshift Software, Inc.
  Distributed under the Boost Software License, Version 1.0.
  (See accompanying file LICENSE_1_0.txt or http://www.boost.org/LICENSE_1_0.txt)
*/

class BoostFilterSimple extends BoostFilters
{
    function echo_filtered($params)
    {
        print $this->prepare_html($params['content']);
    }
}
