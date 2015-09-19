<?php
const DOWNLOAD_URL_PREFIX = 'http://download.opensuse.org/repositories/';

// $interests = [
//   'X11:XOrg' => 'Mesa',
// ];


$packages = [
  '' => [
    'kernel-desktop',
  ],
  'Graphics' => [
    'Mesa',
    'libLLVM',
    'xorg-x11-server',
  ],
  'Desktop' => [
    'libQt5Core5',
    'plasma-framework',
    'plasma5-workspace',
  ],
];
// $packages = [
//   'kernel-desktop',
//   'Mesa',
//   'xorg-x11-server',
//   'libLLVM',
//   'libQt5Core5',
//   'plasma-framework',
//   'plasma5-workspace',
// ];
// file_get_contents

// $list = rpm_list('X11:XOrg', 'openSUSE_Factory');
// var_dump($list['Mesa']);

// $list = array_filter($list, function($k) {
//   global $packages;
//   return in_array($k, $packages);
// }, ARRAY_FILTER_USE_KEY);

// print_r($list);
// echo json_encode($list);

$html = print_packages($packages, rpm_list('tumbleweed'), rpm_list('factory'));
function print_packages(array $packages, $tumbleweed, $factory) {
  $html = '';
  foreach ($packages as $group => $list) {
    if ($group) {
      $html .= "<tr><th colspan=\"4\">$group</th></tr>\n";
    }
    foreach ($list as $package) {
      if (empty($factory[$package])) continue;
      $updated = $tumbleweed[$package]['version'] != $factory[$package]['version'];
      $html .= "<tr title=\"Tumbleweed updated {$tumbleweed[$package]['date']}, Factory updated {$factory[$package]['date']}\"" .
        ($updated ? ' class="updated"' : '') . ">\n" .
        "<td>$package</td>\n" .
        "<td>{$tumbleweed[$package]['version']}</td>\n" .
        "<td>{$factory[$package]['version']}</td>\n" .
        "</tr>\n";
    }
  }
  return $html;
}


// http://download.opensuse.org/repositories/X11:/XOrg/openSUSE_Factory/x86_64/
function rpm_list($project, $repository = 'openSUSE_Factory', $arch = 'x86_64') {
//   static $contents = [];
  static $lists = [];
  $suffix = str_replace(':', ':/', $project) . '/' . $repository . '/' . $arch;
  $url = DOWNLOAD_URL_PREFIX . $suffix;
//   $url = 'Mesa.html';
  $url = 'factory.html';
//   $url = 'http://download.opensuse.org/factory/repo/oss/suse/x86_64/';
if ($project == 'factory') $url = 'http://download.opensuse.org/factory/repo/oss/suse/x86_64/';
if ($project == 'tumbleweed') $url = 'http://download.opensuse.org/tumbleweed/repo/oss/suse/x86_64/';
  if (empty($contents[$suffix])) {
//     if (!($contents[$suffix] = strip_tags(file_get_contents($url)))) {
    if (!($contents = strip_tags(file_get_contents($url)))) {
      echo "Failed to fetch $url.\n";
    }

    if (preg_match_all('/^\s+(.*)-([\d.]+)-.*\.rpm\s+(\d+-\w+-\d+ \d+:\d+)/m', $contents, $matches, PREG_SET_ORDER)) {
      $info = [];
      foreach ($matches as $match) {
        $info[$match[1]] = [
          'version' => $match[2],
          'date' => $match[3],
        ];
      }
      $lists[$suffix] = $info;
//       print_r($info);
    }
  }
  return $lists[$suffix];
}

?>
<html>
  <head>
    <title>openSUSE Tumbleweed</title>
    <link rel='stylesheet prefetch' href='http://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.min.css'>
    <link rel="stylesheet" href="style.css">
  </head>
  <body>
    <div id="tumbleweed">
      <a href="https://en.opensuse.org/Portal:Tumbleweed">
        <img src="https://en.opensuse.org/images/c/c1/Tumbleweed.png" alt="openSUSE Tumbleweed">
      </a>
    </div>
    <div id="versions">
      <h3>Latest <a href="https://build.opensuse.org/project/show/openSUSE:Factory">Factory</a> to <a href="https://openqa.opensuse.org/group_overview/1">Next Tumbleweed</a></h3>
      <table>
        <thead>
          <tr>
            <th>Package</th>
            <th>Tumbleweed</th>
            <th>Factory</th>
          </tr>
        </thead>
        <tbody>
          <?php print $html; ?>
        </tbody>
      </table>
    </div>
  </body>
</html>
