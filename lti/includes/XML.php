<?php
/*
 *  wordpress-lti - LTI connector for WordPress
 *  Copyright (C) 2013  Simon Booth and Stephen P Vickers
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License along
 *  with this program; if not, write to the Free Software Foundation, Inc.,
 *  51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 *  Contact: s.p.booth@stir.ac.uk
 *
 *  Version history:
 *    1.0.00  18-Apr-13  Initial release
 */

require_once dirname(dirname(dirname(dirname(dirname(__FILE__))))) . DIRECTORY_SEPARATOR . 'wp-admin' . DIRECTORY_SEPARATOR . 'admin.php';
require_once 'lib.php';

global $wpdb;

$key = $_GET['lti'];
$consumer = new LTI_Tool_Consumer($key, array($wpdb->base_prefix));

$filename = $consumer->name;
$sanitised = preg_replace('/[^_a-zA-Z0-9-]/','', $filename)  . '.xml';

$siteurl = get_bloginfo('siteurl') . '/?lti';
$iconurl = get_bloginfo('siteurl') . '/wp-content/plugins/lti/wp.png';

$xml = <<< EOD
<?xml version="1.0" encoding="UTF-8"?>
<basic_lti_link
    xmlns="http://www.imsglobal.org/xsd/imsbasiclti_v1p0"
    xmlns:lticm ="http://www.imsglobal.org/xsd/imslticm_v1p0"
    xmlns:lticp ="http://www.imsglobal.org/xsd/imslticp_v1p0"
    xmlns:xsi = "http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation = "http://www.imsglobal.org/xsd/imsbasiclti_v1p0 http://www.imsglobal.org/xsd/lti/ltiv1p0/imsbasiclti_v1p0.xsd
                          http://www.imsglobal.org/xsd/imslticm_v1p0 http://www.imsglobal.org/xsd/lti/ltiv1p0/imslticm_v1p0.xsd
                          http://www.imsglobal.org/xsd/imslticp_v1p0 http://www.imsglobal.org/xsd/lti/ltiv1p0/imslticp_v1p0.xsd">
  <title>WordPress</title>
  <description>Access to WordPress Blogs using LTI</description>
  <launch_url>{$siteurl}</launch_url>
  <secure_launch_url />
  <icon>{$iconurl}</icon>
  <secure_icon />
  <custom />
  <extensions platform="learn">
    <lticm:property name="guid">{$key}</lticm:property>
    <lticm:property name="secret">{$consumer->secret}</lticm:property>
  </extensions>
  <vendor>
    <lticp:code>spvsp</lticp:code>
    <lticp:name>SPV Software Products</lticp:name>
    <lticp:description>Provider of open source educational tools.</lticp:description>
    <lticp:url>http://www.spvsoftwareproducts.com/</lticp:url>
    <lticp:contact>
      <lticp:email>stephen@spvsoftwareproducts.com</lticp:email>
    </lticp:contact>
  </vendor>
</basic_lti_link>
EOD;

header("Cache-Control: public");
header("Content-Description: File Transfer");
header("Content-Length: ". strlen($xml).";");
header("Content-Disposition: attachment; filename=$sanitised");
header("Content-Type: application/octet-stream; ");
header("Content-Transfer-Encoding: binary");

echo $xml;
?>