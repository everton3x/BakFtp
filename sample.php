<?php

/* 
 * Copyright (C) 2014 Everton
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * This file provides a basic example of usage BakFtp class.
 */
require 'bakftp.class.php';

$ftpWrapper = 'ftp://user:secretword@myftpserver.com:21/bakftp/';//In this example, the backup will be saved in bakftp directory server. This directory had to be created beforehand. The class does this automatically to avoid creating directories be randomly giving greater security.

$files = array(
    'c:/mydir/myfile.txt'
    ,'c:/myotherdir/otherfile.jpg'
);

$bak = new BakFtp('sample', 'c:/users/myuser/desktop/');

$bak->setFtpWrapper($ftpWrapper);

$bak->setFilesToBackup($files);

$bak->backup();