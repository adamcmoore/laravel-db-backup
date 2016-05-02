<?php

return array(

    'path'  => storage_path() . '/dumps/',

    'mysql' => array(
        'dump_command_path'    => '',
        'restore_command_path' => '',
        'binlog_path'		   => '/var/log/mysql/'
    ),

    's3'    => array(
        'path' => ''
    ),

);
